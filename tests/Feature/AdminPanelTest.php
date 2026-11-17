<?php

namespace Tests\Feature;

use App\Filament\Resources\BackupJobs\Pages\CreateBackupJob;
use App\Filament\Resources\Servers\Pages\CreateServer;
use App\Filament\Resources\Storages\Pages\CreateStorage;
use App\Models\BackupJob;
use App\Models\Server;
use App\Models\Storage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_pages_load(): void
    {
        foreach (['/admin', '/admin/servers', '/admin/storages', '/admin/backup-jobs', '/admin/runs'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_server_password_is_encrypted(): void
    {
        Livewire::test(CreateServer::class)
            ->fillForm(['name' => 'srv', 'host' => '10.0.0.1', 'port' => 22, 'username' => 'root', 'password' => 'topsecret'])
            ->call('create')
            ->assertHasNoFormErrors();

        $raw = DB::table('servers')->value('password');
        $this->assertNotSame('topsecret', $raw);
        $this->assertSame('topsecret', Server::first()->password);
    }

    public function test_storage_config_is_encrypted_and_type_specific(): void
    {
        Livewire::test(CreateStorage::class)
            ->fillForm([
                'name' => 'win', 'type' => 'sftp',
                'config' => ['host' => 'h', 'port' => 22, 'username' => 'u', 'password' => 'pw123', 'base_path' => '/b'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertStringNotContainsString('pw123', DB::table('storages')->value('config'));
        $this->assertSame('pw123', Storage::first()->config['password']);
    }

    public function test_backup_job_with_two_storages_and_own_retention(): void
    {
        $server = Server::create(['name' => 's', 'host' => 'h', 'port' => 22, 'username' => 'root', 'password' => 'p']);
        $s3 = Storage::create(['name' => 'S3', 'type' => 'local', 'config' => ['path' => '/tmp/a']]);
        $win = Storage::create(['name' => 'Win', 'type' => 'local', 'config' => ['path' => '/tmp/b']]);

        Livewire::test(CreateBackupJob::class)
            ->fillForm([
                'name' => 'site', 'server_id' => $server->id, 'enabled' => true,
                'paths' => ['/var/www/site'], 'mode' => 'stream', 'run_at' => '03:00',
                'weekly_day' => 0, 'monthly_day' => 1,
                'db_config' => ['enabled' => true, 'host' => 'localhost', 'port' => 3306, 'username' => 'u', 'password' => 'dbpw'],
                'storageLinks' => [
                    ['storage_id' => $s3->id, 'keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 6],
                    ['storage_id' => $win->id, 'keep_daily' => 7, 'keep_weekly' => 0, 'keep_monthly' => 0],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $job = BackupJob::first();
        $this->assertCount(2, $job->storageLinks);
        $this->assertSame(0, $job->storageLinks->firstWhere('storage_id', $win->id)->keep_weekly);
        $this->assertSame('dbpw', $job->db_config['password']);
        $this->assertStringNotContainsString('dbpw', DB::table('backup_jobs')->value('db_config'));
    }
}
