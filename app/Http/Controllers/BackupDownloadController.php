<?php

namespace App\Http\Controllers;

use App\Models\RunTarget;
use App\Services\Storage\StorageManager;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Скачивание файла резервной копии: S3 — прямой временной ссылкой, остальные хранилища — потоком через панель. */
class BackupDownloadController extends Controller
{
    public function __invoke(RunTarget $target, int $index, StorageManager $storages): RedirectResponse|StreamedResponse
    {
        abort_unless($target->status === 'success', 404);
        $object = ($target->objects ?? [])[$index] ?? null;
        abort_if($object === null, 404);

        $driver = $storages->driver($target->storage);
        $key = $object['key'];

        if ($url = $driver->temporaryUrl($key, 900)) {
            return redirect()->away($url);
        }

        $size = (int) ($object['size'] ?? 0);

        return response()->streamDownload(function () use ($driver, $key) {
            $in = $driver->readStream($key);
            while (! feof($in) && ($chunk = fread($in, 1024 * 1024)) !== false) {
                echo $chunk;
                flush();
            }
            fclose($in);
        }, basename($key), array_filter(['Content-Length' => $size ?: null, 'Content-Type' => 'application/octet-stream']));
    }
}
