<?php

use App\Ai\Agents\Rag;
use App\Ai\Tools\SearchKnowledgeBase;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;
use Laravel\Ai\Tools\Request;
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

test('authenticated users can ask a question and receive an answer with the sources the agent found', function () {
    Sanctum::actingAs(User::factory()->create());

    $vector = array_fill(0, 1024, 0.1);

    Embeddings::fake(fn ($prompt) => array_map(fn () => $vector, $prompt->inputs));
    Reranking::fake();

    $chunk = Chunk::factory()
        ->for(Document::factory()->create(['title' => 'Laravel Docs']))
        ->create(['content' => 'Laravel is a PHP web framework for building web applications.', 'embedding' => $vector]);

    $search = app(SearchKnowledgeBase::class);
    $this->app->instance(SearchKnowledgeBase::class, $search);

    Rag::fake(function () use ($search): string {
        $search->handle(new Request(['query' => 'Laravel']));

        return 'Laravel is a PHP web framework.';
    });

    $response = $this->postJson('/api/chat', ['question' => 'What is Laravel?']);

    $response->assertOk()
        ->assertJsonPath('data.answer', 'Laravel is a PHP web framework.')
        ->assertJsonPath('data.sources.0.document_id', $chunk->document_id)
        ->assertJsonPath('data.sources.0.document_title', 'Laravel Docs');

    Rag::assertPrompted('What is Laravel?');
});

test('answers without a knowledge base search have no sources', function () {
    Sanctum::actingAs(User::factory()->create());

    Rag::fake(['Pozdrav!']);

    $this->postJson('/api/chat', ['question' => 'Bok!'])
        ->assertOk()
        ->assertJsonPath('data.answer', 'Pozdrav!')
        ->assertJsonPath('data.sources', []);
});

test('leaked control tokens are removed from the answer and the stored conversation', function () {
    Sanctum::actingAs(User::factory()->create());

    Rag::fake(['<|channel>thought <channel|>Pozdrav!']);

    $conversationId = $this->postJson('/api/chat', ['question' => 'Bok!'])
        ->assertOk()
        ->assertJsonPath('data.answer', 'Pozdrav!')
        ->json('data.conversation_id');

    $this->getJson("/api/chat/history/{$conversationId}")
        ->assertOk()
        ->assertJsonPath('data.1.content', 'Pozdrav!');
});

test('an empty answer is asked for once more and only the second answer is stored', function () {
    Sanctum::actingAs(User::factory()->create());

    Rag::fake(['<|channel>thought <channel|>', 'Pozdrav!']);

    $conversationId = $this->postJson('/api/chat', ['question' => 'Bok!'])
        ->assertOk()
        ->assertJsonPath('data.answer', 'Pozdrav!')
        ->json('data.conversation_id');

    $this->getJson("/api/chat/history/{$conversationId}")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.content', 'Pozdrav!');
});

test('new conversations are titled with the question instead of a generated title', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Embeddings::fake();
    Reranking::fake();

    Rag::fake(['Pozdrav!']);

    $this->postJson('/api/chat', ['question' => 'Bok, što sve možeš?'])->assertOk();

    expect($user->conversations()->sole()->title)->toBe('Bok, što sve možeš?');
});
