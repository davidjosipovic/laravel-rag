<?php

use App\Ai\Agents\Rag;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;
use Laravel\Sanctum\Sanctum;

test('guests cannot ask a question', function () {
    $response = $this->postJson('/api/chat', ['question' => 'What is Laravel?']);

    $response->assertUnauthorized();
});

test('a question requires a question field', function () {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->postJson('/api/chat', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('question');
});

test('a question cannot continue a conversation that does not belong to the user', function (string $conversationId) {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/chat', [
        'question' => 'What is Laravel?',
        'conversation_id' => $conversationId,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('conversation_id');
})->with([
    'unknown conversation' => fn () => 'missing-id',
    'another users conversation' => fn () => User::factory()->create()->conversations()->create([
        'id' => (string) Str::uuid7(),
        'title' => 'Someone else',
    ])->id,
]);

test('authenticated users can ask a question and receive an answer with sources', function () {
    Sanctum::actingAs(User::factory()->create());

    $vector = array_fill(0, 1024, 0.1);

    Embeddings::fake(fn ($prompt) => array_map(fn () => $vector, $prompt->inputs));
    Reranking::fake();

    $chunk = Chunk::factory()
        ->for(Document::factory()->create(['title' => 'Laravel Docs']))
        ->create(['embedding' => $vector]);

    Rag::fake(['Laravel is a PHP web framework.']);

    $response = $this->postJson('/api/chat', ['question' => 'What is Laravel?']);

    $response->assertOk()
        ->assertJsonPath('data.answer', 'Laravel is a PHP web framework.')
        ->assertJsonPath('data.sources.0.document_id', $chunk->document_id)
        ->assertJsonPath('data.sources.0.document_title', 'Laravel Docs');

    Rag::assertPrompted('What is Laravel?');
});

test('follow-up questions are searched together with the previous question', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Embeddings::fake();
    Reranking::fake();
    Chunk::factory()->create(['content' => 'Docetaksel se primjenjuje kao infuzija.']);

    $conversation = $user->conversations()->create(['id' => (string) Str::uuid7(), 'title' => 'Docetaksel']);
    $conversation->messages()->create([
        'id' => (string) Str::uuid7(),
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->id,
        'agent' => Rag::class,
        'role' => 'user',
        'content' => 'Kako se primjenjuje docetaksel?',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);

    Rag::fake(['Najčešće nuspojave su umor i mučnina.']);

    $this->postJson('/api/chat', [
        'question' => 'A koje su nuspojave?',
        'conversation_id' => $conversation->id,
    ])->assertOk();

    Reranking::assertReranked(fn ($prompt) => $prompt->query === "Kako se primjenjuje docetaksel?\nA koje su nuspojave?");
    Rag::assertPrompted('A koje su nuspojave?');
});
