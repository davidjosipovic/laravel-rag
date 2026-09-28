<?php

use App\Actions\HybridSearch\RetrieveRelevantChunks;
use App\Ai\Tools\SearchKnowledgeBase;
use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

pest()->extend(TestCase::class);

test('the tool returns the retrieved passages with their headings and remembers the chunks', function () {
    $chunk = (new Chunk)->forceFill(['heading' => 'Docetaksel', 'content' => 'Nalazi najviše 72 sata prije terapije.']);
    $chunk->setRelation('document', (new Document)->forceFill(['title' => 'AI_lijekovi.docx']));

    $retrieval = Mockery::mock(RetrieveRelevantChunks::class);
    $retrieval->shouldReceive('handle')->with('nalazi prije docetaksela')->andReturn(new Collection([$chunk]));

    $tool = new SearchKnowledgeBase($retrieval);

    expect((string) $tool->handle(new Request(['query' => ' nalazi prije docetaksela '])))
        ->toBe("[1] AI_lijekovi.docx — Docetaksel\nNalazi najviše 72 sata prije terapije.")
        ->and($tool->retrievedChunks->all())->toBe([$chunk]);
});

test('the tool says when nothing was found', function () {
    $retrieval = Mockery::mock(RetrieveRelevantChunks::class);
    $retrieval->shouldReceive('handle')->andReturn(new Collection);

    expect((string) (new SearchKnowledgeBase($retrieval))->handle(new Request(['query' => 'Ferinject cijena'])))
        ->toBe('Nema rezultata u bazi znanja za taj upit.');
});
