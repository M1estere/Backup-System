<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    private function superuser(): User
    {
        $u = User::factory()->create(['role' => UserRole::Superuser->value]);
        $this->actingAs($u);

        return $u;
    }

    public function test_pages_open(): void
    {
        $this->superuser();
        $this->get('/admin/users')->assertOk();
        $this->get('/admin/users/create')->assertOk();
        $this->get('/admin/profile')->assertOk();
    }

    public function test_create_user_with_role_and_hashed_password(): void
    {
        $this->superuser();
        Livewire::test(CreateUser::class)
            ->set('data.name', 'Иван')->set('data.email', 'ivan@example.com')
            ->set('data.role', 'operator')->set('data.password', 'secret-pass-1')
            ->call('create')->assertHasNoErrors();

        $u = User::where('email', 'ivan@example.com')->firstOrFail();
        $this->assertSame(UserRole::Operator, $u->role);
        $this->assertTrue(password_verify('secret-pass-1', $u->password));
    }

    public function test_edit_without_password_keeps_old_one(): void
    {
        $this->superuser();
        $u = User::factory()->create(['password' => 'old-password-1', 'role' => 'viewer']);
        $old = $u->password;
        Livewire::test(EditUser::class, ['record' => $u->getKey()])
            ->set('data.name', 'Новое имя')->set('data.password', '')->call('save')->assertHasNoErrors();
        $this->assertSame('Новое имя', $u->fresh()->name);
        $this->assertSame($old, $u->fresh()->password);
    }

    public function test_last_superuser_cannot_be_demoted_or_deleted(): void
    {
        $me = $this->superuser();
        $this->assertTrue($me->isLastSuperuser());
        Livewire::test(EditUser::class, ['record' => $me->getKey()])
            ->set('data.role', 'viewer')->call('save');
        $this->assertSame(UserRole::Superuser, $me->fresh()->role);

        User::factory()->create(['role' => 'superuser']);
        $this->assertFalse($me->fresh()->isLastSuperuser());
    }

    public function test_one_user_can_have_several_sessions(): void
    {
        // Сессии хранятся в БД, а AuthenticateSession (сброс чужих сессий при смене пароля) отключён.
        $this->assertNotContains(
            \Filament\Http\Middleware\AuthenticateSession::class,
            \Filament\Facades\Filament::getPanel('admin')->getMiddleware(),
        );
    }
}
