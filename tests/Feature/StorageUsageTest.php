<?php

namespace Tests\Feature;

use App\Models\BackupJob;
use App\Models\Run;
use App\Models\RunTarget;
use App\Models\Server;
use App\Models\Storage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorageUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_used_size_and_ignores_pruned(): void
    {
        $this->actingAs(User::factory()->create());
        $st = Storage::create(['name' => 'S3 test', 'type' => 's3', 'config' => ['bucket' => 'b']]);
        $srv = Server::create(['name' => 's', 'host' => 'h', 'port' => 22, 'username' => 'u', 'password' => 'p']);
        $job = BackupJob::create(['name' => 'j', 'server_id' => $srv->id, 'paths' => ['/x'], 'run_at' => '02:00']);
        foreach ([[2 * 1024 ** 3, 'success'], [3 * 1024 ** 3, 'success'], [9 * 1024 ** 3, 'pruned']] as [$size, $status]) {
            $run = Run::create(['backup_job_id' => $job->id, 'status' => 'success']);
            RunTarget::create(['run_id' => $run->id, 'storage_id' => $st->id, 'status' => $status, 'size_bytes' => $size]);
        }

        $this->get('/admin/storages')->assertOk()->assertSee('5 ГБ')->assertDontSee('14 ГБ');
    }

    public function test_empty_storage_shows_zero(): void
    {
        $this->actingAs(User::factory()->create());
        Storage::create(['name' => 'S3 empty', 'type' => 's3', 'config' => ['bucket' => 'b']]);
        $this->get('/admin/storages')->assertOk()->assertSee('0 Б');
    }
}
