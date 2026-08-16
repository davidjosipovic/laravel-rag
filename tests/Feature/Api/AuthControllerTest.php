<?php

use App\Models\User;

test('users can register and receive a token', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['user', 'token'])
        ->assertJsonPath('user.email', 'test@example.com');

    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
});

test('registration fails with a duplicate email', function () {
    User::factory()->create(['email' => 'test@example.com']);

    $response = $this->postJson('/api/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('users can login with valid credentials', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['user', 'token']);
});

test('users cannot login with an invalid password', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('authenticated users can logout', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/logout');

    $response->assertOk();
    $this->assertCount(0, $user->fresh()->tokens);
});

test('guests cannot logout', function () {
    $response = $this->postJson('/api/logout');

    $response->assertUnauthorized();
});
