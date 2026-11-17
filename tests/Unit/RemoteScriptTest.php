<?php

namespace Tests\Unit;

use App\Services\Backup\RemoteScript;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class RemoteScriptTest extends TestCase
{
    private function syntaxOk(string $script): bool
    {
        $p = new Process(['sh', '-n']);
        $p->setInput($script)->run();

        return $p->isSuccessful();
    }

    public function test_quote_escapes_single_quotes(): void
    {
        $this->assertSame("'a'\\''b'", RemoteScript::q("a'b"));
        $this->assertSame("'; rm -rf / #'", RemoteScript::q('; rm -rf / #'));
    }

    public function test_tar_script_is_valid_and_quotes_user_input(): void
    {
        $s = RemoteScript::tar("/var/www/my site/'x'", ['cache', '*.log', "we'ird"]);
        $this->assertTrue($this->syntaxOk($s), $s);
        $this->assertStringContainsString("cd '/var/www/my site'", $s);
        $this->assertStringContainsString("--exclude='cache'", $s);
        $this->assertStringContainsString("--exclude='we'\\''ird'", $s);
    }

    public function test_tar_handles_root_and_trailing_slash(): void
    {
        $this->assertStringContainsString("cd '/var/www'", RemoteScript::tar('/var/www/site/'));
        $this->assertTrue($this->syntaxOk(RemoteScript::tar('/')));
    }

    public function test_mysql_password_never_appears_in_arguments_and_is_escaped(): void
    {
        $db = ['username' => 'u"ser', 'password' => 'p"w\'d\\#x', 'host' => 'localhost', 'port' => 3306];
        $s = RemoteScript::mysqldump($db, "my'db");
        $this->assertTrue($this->syntaxOk($s), $s);
        // пароль только внутри содержимого временного файла, а не в командной строке mysqldump
        $this->assertStringNotContainsString('--password', $s);
        $this->assertStringContainsString('--defaults-extra-file="$F"', $s);
        $this->assertStringContainsString('MYSQLDUMP_EXIT=$?', $s);
        $this->assertTrue($this->syntaxOk(RemoteScript::listDatabases($db)));
    }

    public function test_tar_compression_modes_are_valid_and_emit_exit_marker(): void
    {
        foreach (['gzip', 'fast', 'none'] as $mode) {
            $s = RemoteScript::tar('/var/www/site', ['cache'], $mode);
            $this->assertTrue($this->syntaxOk($s), "$mode\n$s");
            $this->assertStringContainsString('TAR_EXIT=$?', $s);
        }
        $this->assertStringContainsString('COMP=cat', RemoteScript::tar('/x', [], 'none'));
        $this->assertStringContainsString('gzip -1', RemoteScript::tar('/x', [], 'fast'));
        $this->assertSame('tar', RemoteScript::tarExtension('none'));
        $this->assertSame('tar.gz', RemoteScript::tarExtension('gzip'));
    }

    /** Реальный запуск сгенерированного скрипта: исключения работают, архив валиден, маркер кода tar есть. */
    public function test_generated_tar_script_really_archives_and_excludes(): void
    {
        $root = sys_get_temp_dir().'/rs_'.bin2hex(random_bytes(4));
        mkdir("$root/site/cache", 0777, true);
        file_put_contents("$root/site/index.php", 'hi');
        file_put_contents("$root/site/cache/tmp", 'x');

        foreach (['gzip' => 'tzf', 'none' => 'tf'] as $mode => $flag) {
            $p = new Process(['sh', '-s']);
            $p->setInput(RemoteScript::tar("$root/site", ['cache'], $mode))->run();
            $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
            $this->assertStringContainsString('TAR_EXIT=0', $p->getErrorOutput());

            $f = "$root/out.$mode";
            file_put_contents($f, $p->getOutput());
            $list = new Process(['tar', $flag, $f]);
            $list->run();
            $this->assertStringContainsString('site/index.php', $list->getOutput(), $mode);
            $this->assertStringNotContainsString('cache', $list->getOutput(), "$mode: исключение должно сработать");
        }

        // несуществующая папка: маркера нет или он ненулевой, и код выхода не 0
        $bad = new Process(['sh', '-s']);
        $bad->setInput(RemoteScript::tar("$root/nope/missing", [], 'gzip'))->run();
        $this->assertNotSame(0, $bad->getExitCode());

        (new Process(['rm', '-rf', $root]))->run();
    }
}
