<?php

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

test('user list page can be rendered and shows records', function () {
    $users = User::factory()->count(3)->create();

    Livewire::test(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords($users);
});

test('a user can be created', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'New User',
            'email' => 'new-user@example.com',
            'password' => 'password',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    assertDatabaseHas('users', ['email' => 'new-user@example.com']);
});

test('creating a user requires a name and email', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => null,
            'email' => null,
            'password' => 'password',
        ])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required', 'email' => 'required']);
});

test('a user can be updated', function () {
    $user = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm([
            'name' => 'Updated Name',
            'password' => 'password',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated Name']);
});

test('users can be bulk deleted from the list page', function () {
    $users = User::factory()->count(3)->create();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords($users)
        ->selectTableRecords($users)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertNotified()
        ->assertCanNotSeeTableRecords($users);

    $users->each(fn (User $user) => assertDatabaseMissing('users', ['id' => $user->id]));
});
