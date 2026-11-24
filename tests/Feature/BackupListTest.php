<?php

namespace Tests\Feature;

use App\Filament\Resources\Backups\Pages\ListBackups;
use App\Models\BackupJob;
use App\Models\Run;
use App\Models\RunTarget;
use App\Models\Server;
use App\Models\Storage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BackupListTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_successful_copies_and_filters_by_storage(): void
    {
        $this->actingAs(User::factory()->create());
        $a = Storage::create(['name' => 'Хранилище-А', 'type' => 's3', 'config' => ['bucket' => 'a']]);
        $b = Storage::create(['name' => 'Хранилище-Б', 'type' => 's3', 'config' => ['bucket' => 'b']]);
        $srv = Server::create(['name' => 's', 'host' => 'h', 'port' => 22, 'username' => 'u', 'password' => 'p']);
        $job = BackupJob::create(['name' => 'Сайт', 'server_id' => $srv->id, 'paths' => ['/x'], 'run_at' => '02:00']);
        $run = Run::create(['backup_job_id' => $job->id, 'status' => 'success', 'tier' => 'weekly', 'started_at' => now()]);
        $ta = RunTarget::create(['run_id' => $run->id, 'storage_id' => $a->id, 'status' => 'success', 'size_bytes' => 1024 ** 2,
            'objects' => [['key' => 'site/2026.tar.gz', 'size' => 1024 ** 2, 'sha256' => 'x']]]);
        $tb = RunTarget::create(['run_id' => $run->id, 'storage_id' => $b->id, 'status' => 'success', 'size_bytes' => 5]);
        $run2 = Run::create(['backup_job_id' => $job->id, 'status' => 'success']);
        $tp = RunTarget::create(['run_id' => $run2->id, 'storage_id' => $a->id, 'status' => 'pruned', 'size_bytes' => 9]);

        $this->get('/admin/backups')->assertOk()->assertSee('Резервные копии');
        Livewire::test(ListBackups::class)
            ->assertCanSeeTableRecords([$ta, $tb])->assertCanNotSeeTableRecords([$tp])
            ->filterTable('storage_id', $a->id)
            ->assertCanSeeTableRecords([$ta])->assertCanNotSeeTableRecords([$tb])
            ->filterTable('tier', 'daily')->assertCanNotSeeTableRecords([$ta]);
    }

    public function test_size_server_filter_and_download(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $st = Storage::create(['name' => 'Лок', 'type' => 'local', 'config' => ['path' => sys_get_temp_dir().'/bp-dl']]);
        @mkdir(sys_get_temp_dir().'/bp-dl/job', 0777, true);
        file_put_contents(sys_get_temp_dir().'/bp-dl/job/a.tar.gz', 'DATA123');
        $s1 = Server::create(['name' => 'Сервер-1', 'host' => 'h', 'port' => 22, 'username' => 'u', 'password' => 'p']);
        $s2 = Server::create(['name' => 'Сервер-2', 'host' => 'h2', 'port' => 22, 'username' => 'u', 'password' => 'p']);
        $j1 = BackupJob::create(['name' => 'J1', 'server_id' => $s1->id, 'paths' => ['/x'], 'run_at' => '02:00']);
        $j2 = BackupJob::create(['name' => 'J2', 'server_id' => $s2->id, 'paths' => ['/x'], 'run_at' => '02:00']);
        $r1 = Run::create(['backup_job_id' => $j1->id, 'status' => 'success', 'tier' => 'daily', 'started_at' => now()]);
        $r2 = Run::create(['backup_job_id' => $j2->id, 'status' => 'success', 'tier' => 'daily', 'started_at' => now()]);
        $t1 = RunTarget::create(['run_id' => $r1->id, 'storage_id' => $st->id, 'status' => 'success', 'size_bytes' => 5 * 1024 ** 2,
            'objects' => [['key' => 'job/a.tar.gz', 'size' => 7, 'sha256' => 'x']]]);
        $t2 = RunTarget::create(['run_id' => $r2->id, 'storage_id' => $st->id, 'status' => 'success', 'size_bytes' => 1]);

        Livewire::test(ListBackups::class)->assertSee('5 МБ')->assertSee('дневная')
            ->filterTable('server', $s2->id)->assertCanSeeTableRecords([$t2])->assertCanNotSeeTableRecords([$t1]);

        $resp = $this->get(route('filament.admin.backups.download', ['target' => $t1->id, 'index' => 0]));
        $resp->assertOk()->assertDownload('a.tar.gz');
        $this->assertSame('DATA123', $resp->streamedContent());
        $this->get(route('filament.admin.backups.download', ['target' => $t1->id, 'index' => 5]))->assertNotFound();

        auth()->logout();
        $this->get(route('filament.admin.backups.download', ['target' => $t1->id, 'index' => 0]))->assertRedirect();
    }
}
