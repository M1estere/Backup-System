<?php

namespace Tests\Feature;

use App\Filament\Resources\BackupJobs\Pages\ListBackupJobs;
use App\Jobs\RunBackupJob;
use App\Models\BackupJob;
use App\Models\Server;
use App\Models\Storage;
use App\Models\User;
use App\Services\Backup\BackupLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class BackupLauncherTest extends TestCase
{
    use RefreshDatabase;

    private function job(bool $withStorage = true): BackupJob
    {
        $server = Server::create(['name' => 's', 'host' => 'h', 'port' => 22, 'username' => 'root', 'password' => 'p']);
        $job = BackupJob::create(['name' => 'j', 'server_id' => $server->id, 'paths' => ['/var/www'], 'mode' => 'stream']);
        if ($withStorage) {
            $st = Storage::create(['name' => 'l', 'type' => 'local', 'config' => ['path' => '/tmp/x']]);
            $job->storageLinks()->create(['storage_id' => $st->id]);
        }

        return $job;
    }

    public function test_refuses_job_without_storages(): void
    {
        Queue::fake();
        $r = app(BackupLauncher::class)->launch($this->job(false));
        $this->assertNull($r['run']);
        $this->assertNotNull($r['error']);
        Queue::assertNothingPushed();
    }

    public function test_creates_run_dispatches_and_blocks_duplicates(): void
    {
        Queue::fake();
        $job = $this->job();

        $r = app(BackupLauncher::class)->launch($job);
        $this->assertNull($r['error']);
        $this->assertSame('queued', $r['run']->status);
        $this->assertSame('manual', $r['run']->trigger);
        Queue::assertPushed(RunBackupJob::class, fn ($j) => $j->runId === $r['run']->id);

        $again = app(BackupLauncher::class)->launch($job);
        $this->assertNull($again['run']);
        $this->assertCount(1, $job->runs);
    }

    public function test_stale_run_does_not_block(): void
    {
        Queue::fake();
        $job = $this->job();
        $old = $job->runs()->create(['status' => 'running']);
        $old->forceFill(['created_at' => now()->subHours(13)])->save();

        $this->assertNotNull(app(BackupLauncher::class)->launch($job)['run']);
    }

    public function test_run_button_in_job_list_starts_backup(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());
        $job = $this->job();

        Livewire::test(ListBackupJobs::class)->callTableAction('runNow', $job)->assertNotified();

        $this->assertSame(1, $job->runs()->where('status', 'queued')->count());
        Queue::assertPushed(RunBackupJob::class);
    }

    public function test_job_folder_name_is_unique_and_stable_on_rename(): void
    {
        $server = Server::create(['name' => 's', 'host' => 'h', 'port' => 22, 'username' => 'root', 'password' => 'p']);
        $a = BackupJob::create(['name' => 'Family project', 'server_id' => $server->id, 'paths' => ['/a']]);
        $b = BackupJob::create(['name' => 'Family project', 'server_id' => $server->id, 'paths' => ['/b']]);

        $this->assertSame('family_project', $a->slug);
        $this->assertSame('family_project_2', $b->slug);

        $a->update(['name' => 'Совсем другое имя']);
        $this->assertSame('family_project', $a->fresh()->slug);
    }
}
