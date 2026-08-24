<?php

use App\Ai\Agents\Rag;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\User;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Sanctum\Sanctum;

test('guests cannot ask a question', function () {
    $response = $this->postJson('/api/ask', ['question' => 'What is Laravel?']);

    $response->assertUnauthorized();
});

test('a question requires a question field', function () {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->postJson('/api/ask', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('question');
});

test('authenticated users can ask a question and receive an answer with sources', function () {
    Sanctum::actingAs(User::factory()->create());

    $vector = array_fill(0, 1536, 0.1);

    Embeddings::fake(fn ($prompt) => array_map(fn () => $vector, $prompt->inputs));

    $chunk = Chunk::factory()
        ->for(Document::factory()->create(['title' => 'Laravel Docs']))
        ->create(['embedding' => $vector]);

    Rag::fake([
        new ToolCall(id: 'call_1', name: 'SimilaritySearch', arguments: ['query' => 'What is Laravel?']),
        ['value' => 'Laravel is a PHP web framework.'],
    ]);

    $response = $this->postJson('/api/ask', [
        'question' => 'What is Laravel?',
        'top_k' => 1,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.answer', 'Laravel is a PHP web framework.')
        ->assertJsonPath('data.sources.0.document_id', $chunk->document_id)
        ->assertJsonPath('data.sources.0.document_title', 'Laravel Docs');

    Rag::assertPrompted('What is Laravel?');
});
