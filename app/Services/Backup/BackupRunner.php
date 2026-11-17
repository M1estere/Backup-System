<?php

namespace App\Services\Backup;

use App\Models\BackupJob;
use App\Models\Run;
use App\Models\RunTarget;
use App\Models\Server;
use App\Models\Storage;
use App\Services\Ssh\ServerChecker;
use App\Services\Ssh\SshExecutor;
use App\Services\Storage\StorageManager;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class BackupRunner
{
    private const SYSTEM_DBS = ['information_schema', 'performance_schema', 'mysql', 'sys'];

    private ?Run $run = null;

    /** @var (callable(): void)|null */
    private $heartbeat = null;

    private int $lastBeat = 0;

    public function __construct(private StorageManager $storages) {}

    /** @param (callable(): void)|null $heartbeat вызывается периодически, чтобы продлить блокировки и показать, что воркер жив */
    public function execute(Run $run, ?callable $heartbeat = null): void
    {
        $this->run = $run;
        $this->heartbeat = $heartbeat;
        $run->update(['status' => 'running', 'started_at' => now(), 'finished_at' => null, 'error' => null]);
        $job = $run->backupJob()->with(['server', 'storageLinks.storage'])->firstOrFail();

        try {
            $this->runJob($run, $job);
        } catch (Throwable $e) {
            $run->log('Ошибка: '.$e->getMessage(), 'error');
            $run->targets()->whereIn('status', ['pending', 'running'])->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }

    /** «Пульс»: обновляет updated_at запуска (по нему сторож находит зависшие) и продлевает блокировки. */
    private function beat(bool $force = false): void
    {
        if ($force || time() - $this->lastBeat >= 15) {
            $this->lastBeat = time();
            $this->run?->touch();
            if ($this->heartbeat) {
                ($this->heartbeat)();
            }
        }
    }

    private function runJob(Run $run, BackupJob $job): void
    {
        $server = $job->server;
        $links = $job->storageLinks;
        if ($links->isEmpty()) {
            throw new RuntimeException('У задания не выбрано ни одного хранилища');
        }
        if ($job->mode === 'archive') {
            $run->log('Режим «архив на сервере» пока не реализован, используется потоковый режим', 'warning');
        }

        // состояние по каждому хранилищу
        $state = [];
        foreach ($links as $l) {
            RunTarget::updateOrCreate(['run_id' => $run->id, 'storage_id' => $l->storage_id], ['status' => 'running', 'size_bytes' => 0, 'objects' => null, 'error' => null]);
            $state[$l->storage_id] = ['name' => $l->storage->name, 'ok' => true, 'error' => null, 'bytes' => 0, 'objects' => []];
        }

        // 1. доступность сервера (заодно запоминается host key)
        $run->log("Подключение к {$server->name} ({$server->host}:{$server->port})", 'info', 'ssh');
        $check = (new ServerChecker)->check($server->fresh());
        if (! $check['ok']) {
            throw new RuntimeException('Сервер недоступен: '.$check['message']);
        }
        $server = $server->fresh();
        $this->beat(true);

        // 2. что именно копируем
        $stamp = now()->format('Y-m-d_H-i-s'); // имя файла = дата_время запуска
        $artifacts = $this->plan($run, $job, $server, $stamp);
        if (! $artifacts) {
            throw new RuntimeException('Нечего копировать: не указаны папки и БД');
        }

        $prefix = $job->slug; // папка = проект
        $sourceFailed = false;

        // 3. по очереди каждый артефакт, поток идёт во все живые хранилища
        foreach ($artifacts as $a) {
            $active = array_keys(array_filter($state, fn ($s) => $s['ok']));
            if (! $active) {
                break;
            }
            $path = $prefix.'/'.$a['name'];
            $run->log("{$a['label']} → {$a['name']}", 'info', $a['kind']);

            $res = $this->stream($server, $a, $path, $active, $run);

            foreach ($res['targets'] as $sid => $t) {
                if ($t['ok'] && $res['sourceOk'] && $t['bytes'] === $res['size']) {
                    $state[$sid]['bytes'] += $t['bytes'];
                    $state[$sid]['objects'][] = ['key' => $path, 'size' => $t['bytes'], 'sha256' => $res['sha256']];
                    $run->log("{$state[$sid]['name']}: записано ".$this->fmt($t['bytes']), 'info', 'upload');

                    continue;
                }
                $this->cleanup($sid, $path); // недописанный объект
                if (! $res['sourceOk']) {
                    continue; // вина источника, а не хранилища
                }
                $why = $t['error'] ?? "размер не совпал (записано {$t['bytes']}, прочитано {$res['size']})";
                $state[$sid]['ok'] = false;
                $state[$sid]['error'] = $why;
                $run->log("{$state[$sid]['name']}: ОШИБКА — $why", 'error', 'upload');
                // неполный набор не должен выглядеть как бэкап: убираем и предыдущие файлы этого запуска
                $this->cleanupAll($sid, $state[$sid]['objects']);
                $state[$sid]['objects'] = [];
                $state[$sid]['bytes'] = 0;
            }
            $this->beat(true);

            if (! $res['sourceOk']) {
                $sourceFailed = true;
                $run->log("Источник: {$res['error']}", 'error', $a['kind']);
                break;
            }
        }

        if ($sourceFailed) {
            // часть файлов уже записана, но набор неполный: удаляем, чтобы его не приняли за рабочий бэкап
            foreach ($state as $sid => &$s) {
                $this->cleanupAll($sid, $s['objects']);
                $s['objects'] = [];
                $s['bytes'] = 0;
            }
            unset($s);
            $run->log('Ранее записанные файлы этого запуска удалены (набор неполный)', 'warning', 'upload');
        }

        // 4. манифест
        $manifest = [
            'run_id' => $run->id, 'job' => $job->name, 'server' => $server->name, 'tier' => $run->tier,
            'created_at' => now()->toIso8601String(),
        ];
        foreach ($state as $sid => $s) {
            if ($s['ok'] && ! $sourceFailed) {
                try {
                    $h = fopen('php://temp', 'w+b');
                    fwrite($h, json_encode($manifest + ['objects' => $s['objects']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    rewind($h);
                    $this->storages->driver(Storage::findOrFail($sid))->put($prefix.'/'.$stamp.'.manifest.json', $h);
                } catch (Throwable $e) {
                    $run->log("{$s['name']}: не удалось записать manifest.json: ".$e->getMessage(), 'warning');
                }
            }
        }

        // 5. итог
        $okCount = 0;
        foreach ($state as $sid => $s) {
            $ok = $s['ok'] && ! $sourceFailed && $s['objects'];
            $ok && $okCount++;
            RunTarget::where(['run_id' => $run->id, 'storage_id' => $sid])->update([
                'status' => $ok ? 'success' : 'failed',
                'size_bytes' => $ok ? $s['bytes'] : 0,
                'objects' => $ok ? $s['objects'] : null,
                'error' => $ok ? null : ($s['error'] ?? ($sourceFailed ? 'ошибка при чтении с источника' : 'нет данных')),
            ]);
        }
        $status = $sourceFailed || $okCount === 0 ? 'failed' : ($okCount === count($state) ? 'success' : 'partial');
        $run->update([
            'status' => $status, 'finished_at' => now(),
            'error' => $status === 'failed' ? 'Бэкап не выполнен (подробности в логе)' : null,
        ]);
        $run->log('Готово: '.['success' => 'успешно', 'partial' => 'частично (часть хранилищ не получила копию)', 'failed' => 'с ошибкой'][$status], $status === 'success' ? 'info' : 'warning');
    }

    /** @return list<array{kind: string, name: string, label: string, script: string}> */
    private function plan(Run $run, BackupJob $job, Server $server, string $stamp): array
    {
        $out = [];
        $paths = array_values(array_filter($job->paths ?? [], 'strlen'));
        $compression = in_array($job->compression, ['gzip', 'fast', 'none'], true) ? $job->compression : 'gzip';
        $ext = RemoteScript::tarExtension($compression);
        foreach ($paths as $path) {
            // одна папка — <дата_время>.tar.gz; несколько — к имени добавляется путь, чтобы архивы не пересекались
            $slug = trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($path, '/')), '_') ?: 'root';
            $out[] = ['kind' => 'tar', 'name' => count($paths) === 1 ? "$stamp.$ext" : "{$stamp}_$slug.$ext", 'label' => "Архив папки $path",
                'script' => RemoteScript::tar($path, $job->excludes ?? [], $compression)];
        }

        $db = $job->db_config ?? [];
        if (! empty($db['enabled'])) {
            $names = array_values(array_filter($db['databases'] ?? []));
            if (! $names) {
                $r = (new SshExecutor($server))->run('sh -s', 60, RemoteScript::listDatabases($db));
                if ($r['code'] !== 0) {
                    throw new RuntimeException('Не удалось получить список баз: '.trim($r['err'] ?: $r['out']));
                }
                $names = array_values(array_diff(array_filter(array_map('trim', explode("\n", $r['out']))), self::SYSTEM_DBS));
                $run->log('Найдены базы: '.implode(', ', $names), 'info', 'dump');
            }
            foreach ($names as $n) {
                // одна база — <дата_время>.sql.gz; несколько — с именем базы
                $dbSlug = preg_replace('/[^A-Za-z0-9._-]+/', '_', $n);
                $out[] = ['kind' => 'dump', 'name' => count($names) === 1 ? "$stamp.sql.gz" : "{$stamp}_$dbSlug.sql.gz", 'label' => "Дамп базы $n",
                    'script' => RemoteScript::mysqldump($db, $n)];
            }
        }

        return $out;
    }

    /** @return array{sourceOk: bool, error: ?string, size: int, sha256: string, targets: array<int, array{ok: bool, bytes: int, error: ?string}>} */
    private function stream(Server $server, array $a, string $path, array $storageIds, Run $run): array
    {
        $tee = new StreamTee($storageIds, $path);
        $ssh = new SshExecutor($server); // держим объект: он владеет временным known_hosts
        $process = $ssh->process('sh -s');
        $process->setInput($a['script'])->setTimeout(null);

        $hash = hash_init('sha256');
        $size = 0;
        $stderr = '';
        $timedOut = false;
        $deadline = time() + (int) config('backup.max_runtime', 43200);

        $process->start();
        while (true) {
            $running = $process->isRunning();
            $err = $process->getIncrementalErrorOutput();
            if ($err !== '' && strlen($stderr) < 20000) {
                $stderr .= $err;
            }
            $data = $process->getIncrementalOutput();
            if ($data !== '') {
                hash_update($hash, $data);
                $size += strlen($data);
                $tee->write($data);
                if ($tee->aliveCount() === 0) {
                    $process->stop(5);
                    break;
                }
            } elseif ($running) {
                usleep(2000);
            }
            $this->beat();
            if (time() > $deadline) {
                $timedOut = true;
                $process->stop(5);
                break;
            }
            if (! $running && $data === '') {
                break;
            }
        }
        $code = $process->getExitCode();
        $this->beat(true);
        $targets = $tee->close();
        $this->beat(true);

        $sourceOk = true;
        $error = null;
        if ($timedOut) {
            $sourceOk = false;
            $error = 'превышено максимальное время выполнения ('.config('backup.max_runtime').' с), процесс остановлен';
        } elseif ($a['kind'] === 'tar') {
            preg_match('/TAR_EXIT=(\d+)/', $stderr, $m);
            $tarCode = isset($m[1]) ? (int) $m[1] : null;
            if ($tarCode === null) {
                $sourceOk = false;
                $error = "tar не запустился или соединение прервано (код $code)";
            } elseif (! in_array($tarCode, [0, 1], true)) { // 1 — «файл изменился во время чтения», допустимо
                $sourceOk = false;
                $error = "tar завершился с кодом $tarCode";
            } elseif ($code !== 0) {
                $sourceOk = false;
                $error = "ошибка сжатия или соединения (код $code)";
            } elseif ($tarCode === 1) {
                $run->log('tar: часть файлов изменилась во время чтения (это нормально для живого сайта)', 'warning', 'tar');
            }
        } else {
            preg_match('/MYSQLDUMP_EXIT=(\d+)/', $stderr, $m);
            if (! isset($m[1]) || (int) $m[1] !== 0 || $code !== 0) {
                $sourceOk = false;
                $error = 'mysqldump завершился с ошибкой'.(isset($m[1]) ? " (код {$m[1]})" : " (код $code)");
            }
        }
        if ($sourceOk && $size === 0) {
            $sourceOk = false;
            $error = 'источник отдал пустой поток';
        }

        $stderrClean = trim(preg_replace('/^(MYSQLDUMP|TAR)_EXIT=\d+\s*$/m', '', $stderr));
        if ($stderrClean !== '') {
            $run->log(mb_substr($stderrClean, 0, 1500), $sourceOk ? 'warning' : 'error', $a['kind']);
        }

        return ['sourceOk' => $sourceOk, 'error' => $error, 'size' => $size, 'sha256' => hash_final($hash), 'targets' => $targets];
    }

    private function cleanup(int $storageId, string $path): void
    {
        try {
            $this->storages->driver(Storage::findOrFail($storageId))->delete($path);
        } catch (Throwable) {
            // объекта могло не быть или хранилище недоступно — в логе это уже отражено
        }
    }

    private function cleanupAll(int $storageId, array $objects): void
    {
        foreach ($objects as $o) {
            $this->cleanup($storageId, $o['key']);
        }
    }

    private function fmt(int $b): string
    {
        $u = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        $i = $b > 0 ? (int) floor(log($b, 1024)) : 0;

        return round($b / 1024 ** $i, 1).' '.$u[$i];
    }
}
