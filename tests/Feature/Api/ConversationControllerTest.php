<?php

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Sanctum\Sanctum;

function createConversationFor(User $user, string $title = 'Test conversation'): Conversation
{
    return $user->conversations()->create([
        'id' => (string) Str::uuid7(),
        'title' => $title,
    ]);
}

function createMessageIn(Conversation $conversation, string $role, string $content): ConversationMessage
{
    return $conversation->messages()->create([
        'id' => (string) Str::uuid7(),
        'participant_type' => $conversation->participant_type,
        'participant_id' => $conversation->participant_id,
        'agent' => 'rag',
        'role' => $role,
        'content' => $content,
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);
}

test('users only see their own conversations', function () {
    $user = User::factory()->create();
    $ownConversation = createConversationFor($user, 'Mine');
    createConversationFor(User::factory()->create(), 'Someone else');

    Sanctum::actingAs($user);

    $this->getJson('/api/chat/list')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownConversation->id)
        ->assertJsonPath('data.0.title', 'Mine')
        ->assertJsonMissingPath('data.0.participant_id');
});

test('users can view the history of their own conversation', function () {
    $user = User::factory()->create();
    $conversation = createConversationFor($user);
    createMessageIn($conversation, 'user', 'What is Laravel?');

    Sanctum::actingAs($user);

    $this->getJson("/api/chat/history/{$conversation->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.role', 'user')
        ->assertJsonPath('data.0.content', 'What is Laravel?')
        ->assertJsonMissingPath('data.0.tool_calls');
});

test('users cannot view the history of another users conversation', function () {
    $conversation = createConversationFor(User::factory()->create());

    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/chat/history/{$conversation->id}")->assertForbidden();
});

test('viewing the history of a missing conversation returns not found', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/chat/history/missing-id')->assertNotFound();
});

test('users can delete their own conversation', function () {
    $user = User::factory()->create();
    $conversation = createConversationFor($user);

    Sanctum::actingAs($user);

    $this->deleteJson("/api/chat/{$conversation->id}")->assertNoContent();

    expect(Conversation::find($conversation->id))->toBeNull();
});

test('users cannot delete another users conversation', function () {
    $conversation = createConversationFor(User::factory()->create());

    Sanctum::actingAs(User::factory()->create());

    $this->deleteJson("/api/chat/{$conversation->id}")->assertForbidden();

    expect(Conversation::find($conversation->id))->not->toBeNull();
});
