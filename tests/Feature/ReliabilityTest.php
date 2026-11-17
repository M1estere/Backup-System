<?php

namespace Tests\Feature;

use App\Jobs\RunBackupJob;
use App\Models\BackupJob;
use App\Models\Run;
use App\Models\Server;
use App\Models\Storage;
use App\Services\Backup\BackupRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private function job(): BackupJob
    {
        $server = Server::create(['name' => 's', 'host' => 'h', 'port' => 22, 'username' => 'root', 'password' => 'p']);
        $st = Storage::create(['name' => 'l', 'type' => 'local', 'config' => ['path' => '/tmp/x']]);
        $job = BackupJob::create(['name' => 'j', 'server_id' => $server->id, 'paths' => ['/a']]);
        $job->storageLinks()->create(['storage_id' => $st->id]);

        return $job;
    }

    public function test_cannot_delete_server_or_storage_in_use(): void
    {
        $job = $this->job();
        $this->assertFalse($job->server->delete());
        $this->assertFalse($job->storageLinks->first()->storage->delete());
        $this->assertDatabaseCount('servers', 1);
        $this->assertDatabaseCount('storages', 1);

        $job->delete();
        $this->assertTrue(Server::first()->delete());
    }

    public function test_reaper_fails_only_stale_runs(): void
    {
        $job = $this->job();
        $stale = $job->runs()->create(['status' => 'running']);
        $stale->forceFill(['updated_at' => now()->subMinutes(45)])->save();
        $fresh = $job->runs()->create(['status' => 'running']);
        $oldQueued = $job->runs()->create(['status' => 'queued']);
        $oldQueued->forceFill(['created_at' => now()->subHours(13)])->save();
        $newQueued = $job->runs()->create(['status' => 'queued']);

        Artisan::call('backup:reap');

        $this->assertSame('failed', $stale->fresh()->status);
        $this->assertSame('running', $fresh->fresh()->status);
        $this->assertSame('failed', $oldQueued->fresh()->status);
        $this->assertSame('queued', $newQueued->fresh()->status);
    }

    public function test_job_does_not_rerun_finished_run(): void
    {
        $run = $this->job()->runs()->create(['status' => 'failed']);
        $runner = $this->mock(BackupRunner::class);
        $runner->shouldNotReceive('execute');

        (new RunBackupJob($run->id))->handle($runner);
    }

    public function test_job_waits_when_server_is_busy(): void
    {
        $job = $this->job();
        $run = $job->runs()->create(['status' => 'queued']);
        $runner = $this->mock(BackupRunner::class);
        $runner->shouldNotReceive('execute');

        $other = Cache::lock('backup-server-'.$job->server_id, 60);
        $this->assertTrue($other->get());

        $queued = (new RunBackupJob($run->id))->withFakeQueueInteractions();
        $queued->handle($runner);
        $queued->assertReleased(30);
        $this->assertSame('queued', $run->fresh()->status);

        $other->release();
    }

    public function test_job_runs_and_releases_lock_when_free(): void
    {
        $job = $this->job();
        $run = $job->runs()->create(['status' => 'queued']);
        $runner = $this->mock(BackupRunner::class);
        $runner->shouldReceive('execute')->once();

        (new RunBackupJob($run->id))->handle($runner);

        $again = Cache::lock('backup-server-'.$job->server_id, 5);
        $this->assertTrue($again->get(), 'блокировка должна освобождаться после запуска');
        $again->release();
    }
}
