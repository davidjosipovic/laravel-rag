<?php

use App\Actions\HybridSearch\RetrieveRelevantChunks;
use App\Models\Chunk;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;

beforeEach(function () {
    Embeddings::fake();
});

test('it returns reranked chunks in order of relevance with their scores', function () {
    config(['ai.rag.max_chunks' => 5]);

    $first = Chunk::factory()->create(['content' => 'Docetaksel se primjenjuje kao infuzija.']);
    $second = Chunk::factory()->create(['content' => 'Docetaksel može uzrokovati umor.']);

    Reranking::fake(fn ($prompt) => [
        new RankedDocument(index: array_search($second->content, $prompt->documents), document: $second->content, score: 0.9),
        new RankedDocument(index: array_search($first->content, $prompt->documents), document: $first->content, score: 0.5),
    ]);

    $chunks = app(RetrieveRelevantChunks::class)->handle('docetaksel');

    expect($chunks->pluck('id')->all())->toBe([$second->id, $first->id])
        ->and($chunks->pluck('relevance')->all())->toBe([0.9, 0.5])
        ->and($chunks->first()->relationLoaded('document'))->toBeTrue();

    Reranking::assertReranked(fn ($prompt): bool => $prompt->limit === 5);
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

test('chunks are reranked together with their heading', function () {
    Chunk::factory()->create(['heading' => 'ERLOTINIB', 'content' => 'Potreban je oprez uz gospinu travu.']);

    Reranking::fake();

    app(RetrieveRelevantChunks::class)->handle('gospina trava');

    Reranking::assertReranked(fn ($prompt): bool => $prompt->documents === ["ERLOTINIB\n\nPotreban je oprez uz gospinu travu."]);
});
