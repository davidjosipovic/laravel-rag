<?php

use App\Actions\HybridSearch\RetrieveRelevantChunks;
use App\Ai\Tools\SearchKnowledgeBase;
use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

pest()->extend(TestCase::class);

test('the tool returns the retrieved passages with their headings and remembers the chunks', function () {
    $chunk = (new Chunk)->forceFill(['id' => 1, 'heading' => 'Docetaksel', 'content' => 'Nalazi najviše 72 sata prije terapije.']);
    $chunk->setRelation('document', (new Document)->forceFill(['title' => 'AI_lijekovi.docx']));

    $retrieval = Mockery::mock(RetrieveRelevantChunks::class);
    $retrieval->shouldReceive('handle')->with('nalazi prije docetaksela')->andReturn(new Collection([$chunk]));

    $tool = new SearchKnowledgeBase($retrieval);

    expect((string) $tool->handle(new Request(['query' => ' nalazi prije docetaksela '])))
        ->toBe("[1] AI_lijekovi.docx — Docetaksel\nNalazi najviše 72 sata prije terapije.")
        ->and($tool->retrievedChunks->all())->toBe([$chunk]);
});

test('the chunks of every search are remembered, each only once', function () {
    $docetaksel = (new Chunk)->forceFill(['id' => 1, 'content' => 'Docetaksel se primjenjuje svaka 3 tjedna.']);
    $docetaksel->setRelation('document', (new Document)->forceFill(['title' => 'AI_lijekovi.docx']));
    $kabazitaksel = (new Chunk)->forceFill(['id' => 2, 'content' => 'Kabazitaksel se primjenjuje svaka 3 tjedna.']);
    $kabazitaksel->setRelation('document', (new Document)->forceFill(['title' => 'AI_lijekovi.docx']));

    $retrieval = Mockery::mock(RetrieveRelevantChunks::class);
    $retrieval->shouldReceive('handle')->with('docetaksel')->andReturn(new Collection([$docetaksel]));
    $retrieval->shouldReceive('handle')->with('kabazitaksel')->andReturn(new Collection([$kabazitaksel, $docetaksel]));

    $tool = new SearchKnowledgeBase($retrieval);
    $tool->handle(new Request(['query' => 'docetaksel']));
    $tool->handle(new Request(['query' => 'kabazitaksel']));

    expect($tool->retrievedChunks->all())->toBe([$docetaksel, $kabazitaksel]);
});

test('the tool says when nothing was found', function () {
    $retrieval = Mockery::mock(RetrieveRelevantChunks::class);
    $retrieval->shouldReceive('handle')->andReturn(new Collection);

    expect((string) (new SearchKnowledgeBase($retrieval))->handle(new Request(['query' => 'Ferinject cijena'])))
        ->toBe('No results in the knowledge base for this query.');
});

test('the model is asked for the full question as the query', function () {
    $schema = (new SearchKnowledgeBase(Mockery::mock(RetrieveRelevantChunks::class)))->schema(new JsonSchemaTypeFactory);

    expect($schema['query']->toArray())->toMatchArray(['type' => 'string'])
        ->and($schema['query']->toArray()['description'])->toContain('in full');
});
