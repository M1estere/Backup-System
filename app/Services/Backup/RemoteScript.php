<?php

namespace App\Services\Backup;

/**
 * Сборка shell-скриптов, которые выполняются на источнике (передаются через stdin в `sh -s`,
 * поэтому пароли не попадают в аргументы процессов и в `ps`).
 */
class RemoteScript
{
    /** Экранирование строки для shell (одинарные кавычки). */
    public static function q(string $s): string
    {
        return "'".str_replace("'", "'\\''", $s)."'";
    }

    private static function nice(): string
    {
        return 'NICE=""; command -v nice >/dev/null 2>&1 && NICE="nice -n 19"; '
            .'command -v ionice >/dev/null 2>&1 && NICE="$NICE ionice -c3"'."\n";
    }

    /**
     * Архив одной папки; в архиве лежит только имя папки (путь относительный).
     * Код выхода tar печатается в stderr как TAR_EXIT=N (0 и 1 — успех: 1 = «файл изменился во время чтения»).
     * Сжатие: gzip (-6), fast (-1) или none (без сжатия — для фото/видео/архивов, в разы быстрее).
     * Если на источнике есть pigz, он используется (2 потока, под nice).
     */
    public static function tar(string $path, array $excludes = [], string $compression = 'gzip'): string
    {
        $path = rtrim($path, '/');
        $parent = $path === '' ? '/' : (dirname($path) ?: '/');
        $base = $path === '' ? '.' : basename($path);
        $ex = implode(' ', array_map(fn ($e) => '--exclude='.self::q($e), array_filter($excludes, 'strlen')));

        $level = $compression === 'fast' ? '1' : '6';
        $comp = $compression === 'none'
            ? "COMP=cat\n"
            : 'COMP="gzip -'.$level."\"\ncommand -v pigz >/dev/null 2>&1 && COMP=\"pigz -p 2 -".$level."\"\n";

        return self::nice()
            .'cd '.self::q($parent)." || exit 98\n"
            .$comp
            .'( $NICE tar -cf - '.$ex.' '.self::q($base).'; echo "TAR_EXIT=$?" >&2 ) | $NICE $COMP'."\n"
            .'exit $?'."\n";
    }

    public static function tarExtension(string $compression): string
    {
        return $compression === 'none' ? 'tar' : 'tar.gz';
    }

    /** Блок, создающий временный defaults-файл с реквизитами БД (удаляется при выходе). */
    private static function mysqlAuth(array $db): string
    {
        $esc = fn ($v) => str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v);
        $content = "[client]\nuser=\"".$esc($db['username'] ?? '')."\"\n"
            .'password="'.$esc($db['password'] ?? '')."\"\n"
            .'host="'.$esc($db['host'] ?? 'localhost')."\"\n"
            .'port='.((int) ($db['port'] ?? 3306))."\n";

        return "umask 077\n"
            ."F=\$(mktemp) || exit 97\n"
            ."trap 'rm -f \"\$F\"' EXIT\n"
            .'printf %s '.self::q($content).' > "$F"'."\n";
    }

    /** Список баз (без системных). */
    public static function listDatabases(array $db): string
    {
        return self::mysqlAuth($db).'mysql --defaults-extra-file="$F" -N -B -e '.self::q('SHOW DATABASES')."\n";
    }

    /** Дамп одной базы в gzip. Код выхода mysqldump печатается в stderr как MYSQLDUMP_EXIT=N. */
    public static function mysqldump(array $db, string $database): string
    {
        return self::mysqlAuth($db).self::nice()
            .'( $NICE mysqldump --defaults-extra-file="$F" --single-transaction --quick --routines --triggers --events '
            .'--default-character-set=utf8mb4 '.self::q($database).'; echo "MYSQLDUMP_EXIT=$?" >&2 ) | gzip'."\n";
    }
}
