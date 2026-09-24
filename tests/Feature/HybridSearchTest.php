<?php

use App\Actions\HybridSearch\FullTextSearch;
use App\Actions\HybridSearch\SimilaritySearch;
use App\Models\Chunk;
use Laravel\Ai\Embeddings;

function unitVector(int $index): array
{
    $vector = array_fill(0, 1024, 0.0);
    $vector[$index] = 1.0;

    return $vector;
}

test('full text search matches chunks containing only some of the question words', function () {
    $matching = Chunk::factory()->create([
        'content' => 'Potrebno je uzorkovati krvne nalaze najviše 72 sata prije primanja terapije.',
    ]);
    Chunk::factory()->create(['content' => 'Lijek se uzima u obliku tableta dva puta dnevno.']);

    $ids = app(FullTextSearch::class)->handle('Koliko najranije treba izvaditi krvne nalaze?', 10);

    expect($ids)->toBe([$matching->id]);
});

test('full text search matches other case endings of the same word', function () {
    $matching = Chunk::factory()->create(['content' => 'Docetaksel se primjenjuje kao infuzija.']);

    $ids = app(FullTextSearch::class)->handle('Kako se daje docetaksela?', 10);

    expect($ids)->toContain($matching->id);
});

test('full text search ranks chunks with more matching terms first', function () {
    $partial = Chunk::factory()->create(['content' => 'Krvne pretrage se rade redovito.']);
    $best = Chunk::factory()->create(['content' => 'Krvne nalaze treba uzorkovati prije docetaksela.']);

    $ids = app(FullTextSearch::class)->handle('krvne nalaze prije docetaksela', 10);

    expect($ids)->toBe([$best->id, $partial->id]);
});

test('full text search returns nothing for a query without searchable words', function () {
    Chunk::factory()->create();

    expect(app(FullTextSearch::class)->handle('?! a', 10))->toBe([]);
});

test('similarity search embeds the query with an instruction and orders by similarity', function () {
    Embeddings::fake(fn ($prompt) => array_map(fn () => unitVector(0), $prompt->inputs));

    $similar = Chunk::factory()->create(['embedding' => unitVector(0)]);
    Chunk::factory()->create(['embedding' => unitVector(1)]);

    $ids = app(SimilaritySearch::class)->handle('Koje su nuspojave?', 10);

    expect($ids)->toBe([$similar->id]);

    Embeddings::assertGenerated(fn ($prompt) => $prompt->contains('Instruct:')
        && $prompt->contains('Query: Koje su nuspojave?'));
});
