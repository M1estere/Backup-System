<?php

namespace App\Services\Storage;

use Aws\S3\S3Client;

class S3Driver implements StorageDriver
{
    private S3Client $client;

    private string $bucket;

    private string $prefix;

    public function __construct(private array $config)
    {
        $args = [
            'version' => 'latest',
            'region' => $config['region'] ?? 'us-east-1',
            'credentials' => ['key' => $config['key'], 'secret' => $config['secret']],
        ];
        if (! empty($config['endpoint'])) {
            $args['endpoint'] = $config['endpoint'];
        }
        if (! empty($config['path_style'])) {
            $args['use_path_style_endpoint'] = true;
        }
        $this->client = new S3Client($args);
        $this->bucket = $config['bucket'];
        $this->prefix = trim($config['prefix'] ?? '', '/');
    }

    public function test(): array
    {
        try {
            $this->client->headBucket(['Bucket' => $this->bucket]);
            $key = $this->key('.probe-'.bin2hex(random_bytes(4)));
            $this->client->putObject(['Bucket' => $this->bucket, 'Key' => $key, 'Body' => 'ok']);
            $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);

            return ['ok' => true, 'message' => 'Бакет доступен, запись и удаление работают'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Потоковая загрузка без перемотки: читаем часть за частью (64 МБ, после 1000-й части — 128 МБ,
     * что даёт до ~1,2 ТБ при лимите S3 в 10 000 частей) и отправляем через multipart.
     */
    public function put(string $path, $stream): int
    {
        $key = $this->key($path);
        $partSize = max(5, (int) ($this->config['part_size_mb'] ?? 64)) * 1024 * 1024;
        $extra = ! empty($this->config['storage_class']) ? ['StorageClass' => $this->config['storage_class']] : [];

        $chunk = $this->readChunk($stream, $partSize);
        if (strlen($chunk) < $partSize) { // всё помещается в одну часть
            $this->client->putObject(['Bucket' => $this->bucket, 'Key' => $key, 'Body' => $chunk] + $extra);
            $total = strlen($chunk);
        } else {
            $uploadId = $this->client->createMultipartUpload(['Bucket' => $this->bucket, 'Key' => $key] + $extra)['UploadId'];
            try {
                $parts = [];
                $total = 0;
                $n = 1;
                while ($chunk !== '') {
                    $r = $this->client->uploadPart([
                        'Bucket' => $this->bucket, 'Key' => $key, 'UploadId' => $uploadId, 'PartNumber' => $n, 'Body' => $chunk,
                    ]);
                    $parts[] = ['ETag' => $r['ETag'], 'PartNumber' => $n];
                    $total += strlen($chunk);
                    $n++;
                    if ($n > 10000) {
                        throw new \RuntimeException('Превышен лимит S3 в 10 000 частей (файл слишком большой)');
                    }
                    $chunk = $this->readChunk($stream, $n > 1000 ? $partSize * 2 : $partSize);
                }
                $this->client->completeMultipartUpload([
                    'Bucket' => $this->bucket, 'Key' => $key, 'UploadId' => $uploadId, 'MultipartUpload' => ['Parts' => $parts],
                ]);
            } catch (\Throwable $e) {
                try {
                    $this->client->abortMultipartUpload(['Bucket' => $this->bucket, 'Key' => $key, 'UploadId' => $uploadId]);
                } catch (\Throwable) {
                }
                throw $e;
            }
        }

        return (int) $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $key])['ContentLength'] ?: $total;
    }

    /** Прочитать ровно $size байт (или до конца потока). */
    private function readChunk($stream, int $size): string
    {
        $buf = '';
        while (strlen($buf) < $size && ! feof($stream)) {
            $d = fread($stream, min(1048576, $size - strlen($buf)));
            if ($d === false || $d === '') {
                if (feof($stream)) {
                    break;
                }
                usleep(1000);

                continue;
            }
            $buf .= $d;
        }

        return $buf;
    }

    public function list(string $prefix = ''): array
    {
        $out = [];
        $p = $this->client->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket, 'Prefix' => $this->key($prefix)]);
        foreach ($p as $page) {
            foreach ($page['Contents'] ?? [] as $o) {
                $out[] = [
                    'path' => $this->prefix !== '' ? substr($o['Key'], strlen($this->prefix) + 1) : $o['Key'],
                    'size' => (int) $o['Size'],
                    'modified' => $o['LastModified']->getTimestamp(),
                ];
            }
        }

        return $out;
    }

    public function delete(string $path): void
    {
        $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $this->key($path)]);
    }

    public function readStream(string $path)
    {
        $r = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $this->key($path)]);

        return $r['Body']->detach();
    }

    public function temporaryUrl(string $path, int $seconds = 900): ?string
    {
        $cmd = $this->client->getCommand('GetObject', ['Bucket' => $this->bucket, 'Key' => $this->key($path)]);

        return (string) $this->client->createPresignedRequest($cmd, "+{$seconds} seconds")->getUri();
    }

    private function key(string $path): string
    {
        $path = ltrim($path, '/');

        return $this->prefix !== '' ? $this->prefix.'/'.$path : $path;
    }
}
