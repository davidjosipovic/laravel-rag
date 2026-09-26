<?php

use App\Actions\HybridSearch\RetrieveRelevantChunks;
use App\Models\Chunk;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;

beforeEach(function () {
    Embeddings::fake();
});

test('it returns reranked chunks in order of relevance', function () {
    $first = Chunk::factory()->create(['content' => 'Docetaksel se primjenjuje kao infuzija.']);
    $second = Chunk::factory()->create(['content' => 'Docetaksel može uzrokovati umor.']);

    Reranking::fake(fn ($prompt) => [
        new RankedDocument(index: array_search($second->content, $prompt->documents), document: $second->content, score: 0.9),
        new RankedDocument(index: array_search($first->content, $prompt->documents), document: $first->content, score: 0.5),
    ]);

    $chunks = app(RetrieveRelevantChunks::class)->handle('docetaksel');

    expect($chunks->pluck('id')->all())->toBe([$second->id, $first->id])
        ->and($chunks->first()->relationLoaded('document'))->toBeTrue();
});

test('it drops chunks the reranker considers irrelevant', function () {
    config(['ai.rag.min_relevance' => 0.3]);

    $relevant = Chunk::factory()->create(['content' => 'Docetaksel se primjenjuje kao infuzija.']);
    $irrelevant = Chunk::factory()->create(['content' => 'Docetaksel se čuva u hladnjaku.']);

    Reranking::fake(fn ($prompt) => [
        new RankedDocument(index: array_search($relevant->content, $prompt->documents), document: $relevant->content, score: 0.3),
        new RankedDocument(index: array_search($irrelevant->content, $prompt->documents), document: $irrelevant->content, score: 0.1),
    ]);

    $chunks = app(RetrieveRelevantChunks::class)->handle('docetaksel');

    expect($chunks->pluck('id')->all())->toBe([$relevant->id]);
});

test('it returns nothing without calling the reranker when no chunk matches', function () {
    Reranking::fake();

    expect(app(RetrieveRelevantChunks::class)->handle('?!'))->toBeEmpty();

    Reranking::assertNothingReranked();
});
