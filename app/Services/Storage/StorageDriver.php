<?php

namespace App\Services\Storage;

interface StorageDriver
{
    /** Проверка подключения и права записи. @return array{ok: bool, message: string} */
    public function test(): array;

    /** Записать поток в объект/файл по относительному пути. Возвращает число записанных байт. */
    public function put(string $path, $stream): int;

    /** @return list<array{path: string, size: int, modified: int}> объекты под префиксом */
    public function list(string $prefix = ''): array;

    public function delete(string $path): void;

    /** Открыть объект на чтение потоком (для скачивания). @return resource */
    public function readStream(string $path);

    /** Временная прямая ссылка на скачивание, если драйвер умеет (S3). */
    public function temporaryUrl(string $path, int $seconds = 900): ?string;
}
