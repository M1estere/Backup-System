<?php

namespace App\Services\Ssh;

use App\Models\Server;
use RuntimeException;
use Symfony\Component\Process\Process;

class SshExecutor
{
    private ?string $knownHostsFile = null;

    public function __construct(private Server $server) {}

    public function __destruct()
    {
        if ($this->knownHostsFile && is_file($this->knownHostsFile)) {
            @unlink($this->knownHostsFile);
        }
    }

    /** Процесс для удалённой команды (не запущен). stdout можно читать потоково. */
    public function process(string $remoteCommand, ?float $timeout = null): Process
    {
        $s = $this->server;
        $cmd = [
            'sshpass', '-e', 'ssh',
            '-p', (string) $s->port,
            '-o', 'PreferredAuthentications=password,keyboard-interactive',
            '-o', 'PubkeyAuthentication=no',
            '-o', 'ConnectTimeout=15',
            '-o', 'ServerAliveInterval=30',
            '-o', 'ServerAliveCountMax=6',
            '-o', 'LogLevel=ERROR',
            '-o', 'UserKnownHostsFile='.$this->knownHosts(),
            '-o', 'StrictHostKeyChecking='.($s->host_key ? 'yes' : 'no'),
            '-o', 'HostKeyAlgorithms=ssh-ed25519,ecdsa-sha2-nistp256,rsa-sha2-512,rsa-sha2-256,ssh-rsa',
            $s->username.'@'.$s->host,
            $remoteCommand,
        ];

        return new Process($cmd, null, ['SSHPASS' => $s->password], null, $timeout);
    }

    /** Выполнить команду и вернуть результат. */
    public function run(string $remoteCommand, float $timeout = 60, ?string $input = null): array
    {
        $p = $this->process($remoteCommand, $timeout);
        if ($input !== null) {
            $p->setInput($input);
        }
        $p->run();

        return ['code' => $p->getExitCode(), 'out' => $p->getOutput(), 'err' => $p->getErrorOutput()];
    }

    /** Получить host key сервера (для TOFU). */
    public static function scanHostKey(Server $server): ?string
    {
        $p = new Process(['ssh-keyscan', '-T', '10', '-p', (string) $server->port, '-t', 'ed25519,ecdsa,rsa', $server->host]);
        $p->run();
        $lines = array_filter(array_map('trim', explode("\n", $p->getOutput())), fn ($l) => $l !== '' && ! str_starts_with($l, '#'));
        // предпочитаем ed25519
        usort($lines, fn ($a, $b) => (int) str_contains($b, 'ssh-ed25519') <=> (int) str_contains($a, 'ssh-ed25519'));

        return $lines ? $lines[0] : null;
    }

    private function knownHosts(): string
    {
        if ($this->knownHostsFile === null) {
            $this->knownHostsFile = tempnam(sys_get_temp_dir(), 'kh');
            if ($this->server->host_key) {
                file_put_contents($this->knownHostsFile, $this->server->host_key."\n");
            }
        }
        if ($this->knownHostsFile === false) {
            throw new RuntimeException('Не удалось создать known_hosts');
        }

        return $this->knownHostsFile;
    }
}
