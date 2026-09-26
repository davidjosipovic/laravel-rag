<?php

use App\Actions\HybridSearch\RetrieveRelevantChunks;
use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake();
    Process::fake(['git rev-parse *' => Process::result('abc1234')]);

    Storage::disk('local')->put('evaluation/questions.csv', implode("\n", [
        "\u{FEFF}id,kategorija,pitanje,ocekivani_odgovor,izvor",
        'Q01,cinjenicno,Koliko prije docetaksela treba izvaditi krvne nalaze?,Najviše 72 sata prije planirane terapije.,AI_lijekovi.docx',
        'Q02,usporedba,Kako se razlikuju temozolomid i docetaksel?,Temozolomid se uzima kod glioma.,AI_lijekovi.docx; gliom.docx',
        'Q64,neodgovorivo,Koliko košta Ferinject?,Nije navedeno u dokumentima.,AI_lijekovi.docx',
    ]));
});

/**
 * @param  array<string, list<Chunk>>  $chunks  Retrieved chunks per question.
 */
function fakeRetrieval(array $chunks): void
{
    test()->mock(RetrieveRelevantChunks::class)
        ->shouldReceive('handle')
        ->andReturnUsing(fn (string $question): Collection => new Collection($chunks[$question] ?? []));
}

function chunkFrom(string $document, string $content): Chunk
{
    return Chunk::factory()
        ->for(Document::factory()->create(['title' => $document]))
        ->create(['heading' => 'Docetaksel', 'content' => $content]);
}

/**
 * @return array<string, mixed>
 */
function storedRetrieval(): array
{
    $files = Storage::disk('local')->files('evaluation/retrieval');

    return json_decode(Storage::disk('local')->get(end($files)), true);
}

test('the retrieved passages are checked against the sources and the expected answer', function () {
    fakeRetrieval([
        'Koliko prije docetaksela treba izvaditi krvne nalaze?' => [chunkFrom('AI_lijekovi.docx', 'Krvne nalaze uzorkovati najviše 72 sata prije terapije.')],
        'Kako se razlikuju temozolomid i docetaksel?' => [chunkFrom('gliom.docx', 'Temozolomid se primjenjuje kod glioma.')],
        'Koliko košta Ferinject?' => [chunkFrom('AI_lijekovi.docx', 'Ferinject se daje infuzijom.')],
    ]);

    $this->artisan('rag:evaluate-retrieval', ['--label' => 'Bigger chunks'])
        ->expectsOutputToContain('Pogođen izvor')
        ->expectsOutputToContain('1 (Q64)')
        ->assertSuccessful();

    $results = storedRetrieval();
    [$factual, $comparison, $unanswerable] = $results['questions'];

    expect($results)->toMatchArray(['label' => 'Bigger chunks', 'commit' => 'abc1234'])
        ->and($factual)->toMatchArray(['source_hit' => true, 'coverage' => 0.8, 'missing_terms' => ['plani']])
        ->and($factual['chunks'][0])->toMatchArray(['document' => 'AI_lijekovi.docx', 'heading' => 'Docetaksel'])
        ->and($comparison)->toMatchArray(['source_hit' => true, 'coverage' => 0.667, 'missing_terms' => ['uzima']])
        ->and($unanswerable)->toMatchArray(['source_hit' => null, 'coverage' => null]);
});

test('a question that retrieves nothing misses its source', function () {
    fakeRetrieval([]);

    $this->artisan('rag:evaluate-retrieval', ['--only' => 'Q01'])
        ->expectsOutputToContain('1 (Q01)')
        ->assertSuccessful();

    expect(storedRetrieval()['questions'][0])->toMatchArray(['source_hit' => false, 'coverage' => 0.0, 'chunks' => []]);
});

test('changes against the previous results are listed', function () {
    Storage::disk('local')->put('evaluation/retrieval/2026-09-01_120000_baseline.json', json_encode([
        'label' => 'baseline',
        'questions' => [
            ['id' => 'Q01', 'source_hit' => false, 'coverage' => 0.0],
            ['id' => 'Q64', 'source_hit' => null, 'coverage' => null],
        ],
    ]));
    fakeRetrieval([
        'Koliko prije docetaksela treba izvaditi krvne nalaze?' => [chunkFrom('AI_lijekovi.docx', 'Najviše 72 sata prije planirane terapije.')],
    ]);

    $this->artisan('rag:evaluate-retrieval', ['--only' => 'Q01,Q64', '--compare' => 'latest'])
        ->expectsOutputToContain('Usporedba s baseline')
        ->expectsTable(['Pitanje', 'Prije', 'Sad'], [
            ['Q01', 'ne / 0,0 %', 'da / 100,0 %'],
        ])
        ->assertSuccessful();
});

test('the evaluation stops when the reranker stays rate limited', function () {
    $this->mock(RetrieveRelevantChunks::class)
        ->shouldReceive('handle')
        ->times(3)
        ->andThrow(new RuntimeException('Application rate limited by AI provider [cohere].'));

    $this->artisan('rag:evaluate-retrieval', ['--only' => 'Q01']);
})->throws(RuntimeException::class, 'Retrieval is not available');
