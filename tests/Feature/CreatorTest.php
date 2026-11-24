<?php

namespace Tests\Feature;

use App\Filament\Resources\Servers\Pages\CreateServer;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Models\BackupJob;
use App\Models\Project;
use App\Models\Server;
use App\Models\Storage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CreatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_is_recorded_and_shown(): void
    {
        $u = User::factory()->create(['name' => 'Пётр Админов']);
        $this->actingAs($u);

        Livewire::test(CreateServer::class)
            ->set('data.name', 'srv')->set('data.host', '10.0.0.1')->set('data.port', 22)
            ->set('data.username', 'root')->set('data.password', 'x')->call('create')->assertHasNoErrors();
        $srv = Server::firstOrFail();
        $this->assertSame($u->id, $srv->created_by);

        $st = Storage::create(['name' => 'st', 'type' => 'local', 'config' => ['path' => '/tmp/x']]);
        $job = BackupJob::create(['name' => 'j', 'server_id' => $srv->id, 'paths' => ['/a']]);
        $pr = Project::create(['name' => 'p', 'parent_server_id' => $srv->id, 'source_path' => '/a', 'sync_at' => '03:00']);
        foreach ([$st, $job, $pr] as $m) {
            $this->assertSame($u->id, $m->fresh()->created_by);
        }

        Livewire::test(EditServer::class, ['record' => $srv->getKey()])->assertSee('Добавил: Пётр Админов');
    }

    public function test_old_records_show_unknown_and_console_creates_without_creator(): void
    {
        $this->actingAs(User::factory()->create());
        $srv = Server::create(['name' => 'old', 'host' => 'h', 'port' => 22, 'username' => 'u', 'password' => 'p', 'created_by' => null]);
        // запись, созданная без пользователя (консоль), остаётся без автора
        auth()->logout();
        $other = Server::create(['name' => 'cli', 'host' => 'h2', 'port' => 22, 'username' => 'u', 'password' => 'p']);
        $this->assertNull($other->created_by);
        $this->actingAs(User::factory()->create());
        Livewire::test(EditServer::class, ['record' => $other->getKey()])->assertSee('Добавил: не указано');
    }
}
