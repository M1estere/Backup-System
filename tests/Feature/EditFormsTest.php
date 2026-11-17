<?php

namespace Tests\Feature;

use App\Filament\Resources\BackupJobs\Pages\EditBackupJob;
use App\Filament\Resources\Storages\Pages\EditStorage;
use App\Models\BackupJob;
use App\Models\Server;
use App\Models\Storage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EditFormsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_storage_edit_form_is_prefilled_and_keeps_secret(): void
    {
        $st = Storage::create(['name' => 'S3', 'type' => 's3', 'config' => [
            'bucket' => 'bkt', 'region' => 'eu-west-1', 'key' => 'AKIA', 'secret' => 'SECRET', 'prefix' => 'p',
        ]]);

        $c = Livewire::test(EditStorage::class, ['record' => $st->getRouteKey()])
            ->assertFormSet(['config.bucket' => 'bkt', 'config.region' => 'eu-west-1', 'config.key' => 'AKIA'])
            ->fillForm(['name' => 'S3 renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $st->refresh();
        $this->assertSame('S3 renamed', $st->name);
        $this->assertSame('SECRET', $st->config['secret'], 'секрет не должен затираться при сохранении с пустым полем');
        $this->assertSame('bkt', $st->config['bucket']);
    }

    public function test_job_edit_form_keeps_database_settings(): void
    {
        $server = Server::create(['name' => 's', 'host' => 'h', 'port' => 22, 'username' => 'root', 'password' => 'p']);
        $st = Storage::create(['name' => 'l', 'type' => 'local', 'config' => ['path' => '/tmp/x']]);
        $job = BackupJob::create([
            'name' => 'j', 'server_id' => $server->id, 'paths' => ['/a'], 'run_at' => '03:00', 'mode' => 'stream',
            'db_config' => ['enabled' => true, 'host' => 'localhost', 'port' => 3306, 'username' => 'u', 'password' => 'dbpw', 'databases' => ['x']],
        ]);
        $job->storageLinks()->create(['storage_id' => $st->id]);

        Livewire::test(EditBackupJob::class, ['record' => $job->getRouteKey()])
            ->assertFormSet(['db_config.enabled' => true, 'db_config.username' => 'u'])
            ->fillForm(['name' => 'j2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $job->refresh();
        $this->assertSame('j2', $job->name);
        $this->assertTrue($job->db_config['enabled']);
        $this->assertSame('dbpw', $job->db_config['password']);
        $this->assertSame(['x'], $job->db_config['databases']);
    }
}
