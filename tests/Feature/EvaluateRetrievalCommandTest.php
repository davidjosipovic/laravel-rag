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
    Process::fake(['git describe *' => Process::result('abc1234')]);

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
        'Koliko prije docetaksela treba izvaditi krvne nalaze?' => [chunkFrom('AI_lijekovi.docx', 'Krvne nalaze uzorkovati najviše 72 sata prije terapije.')->setAttribute('relevance', 0.81234)],
        'Kako se razlikuju temozolomid i docetaksel?' => [chunkFrom('gliom.docx', 'Temozolomid se primjenjuje kod glioma.')],
        'Koliko košta Ferinject?' => [chunkFrom('AI_lijekovi.docx', 'Ferinject se daje infuzijom.')],
    ]);

    $this->artisan('rag:evaluate-retrieval', ['--label' => 'Bigger chunks'])
        ->expectsOutputToContain('Pogođen izvor')
        ->expectsOutputToContain('1 (Q64)')
        ->assertSuccessful();

    $results = storedRetrieval();
    [$factual, $comparison, $unanswerable] = $results['questions'];

    Process::assertRan('git describe --always --dirty');

    expect($results)->toMatchArray(['label' => 'Bigger chunks', 'commit' => 'abc1234'])
        ->and($factual)->toMatchArray(['source_hit' => true, 'coverage' => 0.8, 'missing_terms' => ['plani']])
        ->and($factual['chunks'][0])->toMatchArray(['document' => 'AI_lijekovi.docx', 'heading' => 'Docetaksel', 'relevance' => 0.8123])
        ->and($comparison)->toMatchArray(['source_hit' => true, 'coverage' => 0.667, 'missing_terms' => ['uzima']])
        ->and($unanswerable)->toMatchArray(['source_hit' => null, 'coverage' => null]);
});

test('numbers written as words match digits and the false premise label is not an expected term', function () {
    Storage::disk('local')->put('evaluation/questions.csv', implode("\n", [
        'id,kategorija,pitanje,ocekivani_odgovor,izvor',
        'Q03,cinjenicno,Kojim danima ciklusa se primjenjuje nab-paklitaksel?,"Najčešće 1., 8. i 15. dan ciklusa.",AI_lijekovi.docx',
        'Q73,kriva_premisa,Koliko tableta dnevno se uzima trastuzumab?,Kriva premisa – trastuzumab je infuzija.,AI_lijekovi.docx',
    ]));
    fakeRetrieval([
        'Kojim danima ciklusa se primjenjuje nab-paklitaksel?' => [chunkFrom('AI_lijekovi.docx', 'Najčešće na prvi, osmi i petnaesti dan ciklusa.')],
        'Koliko tableta dnevno se uzima trastuzumab?' => [chunkFrom('AI_lijekovi.docx', 'Trastuzumab se daje kao infuzija.')],
    ]);

    $this->artisan('rag:evaluate-retrieval')->assertSuccessful();

    expect(array_column(storedRetrieval()['questions'], 'coverage'))->toEqual([1, 1]);
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
            ['Q01', 'ne / 0,0 % / –', 'da / 100,0 % / –'],
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

test('passage recall and reciprocal rank are measured against the expected passages', function () {
    Storage::disk('local')->put('evaluation/questions.csv', implode("\n", [
        'id,kategorija,pitanje,ocekivani_odgovor,izvor,odlomci',
        'Q49,agregacija,Koji lijekovi uzrokuju sindrom šaka-stopalo?,Kapecitabin i regorafenib.,AI_lijekovi.docx,Kapecitabin;REGORAFENIB',
        'Q26,cinjenicno,Što je zlatni standard?,Magnetska rezonancija.,gliom.docx,Radiološka dijagnostika|Dijagnoza',
        'Q64,neodgovorivo,Koliko košta Ferinject?,Nije navedeno.,,',
    ]));

    $chunk = fn (string $heading): Chunk => Chunk::factory()->create(['heading' => $heading, 'content' => 'Tekst.']);

    fakeRetrieval([
        'Koji lijekovi uzrokuju sindrom šaka-stopalo?' => [$chunk('Paklitaksel'), $chunk('Kapecitabin')],
        'Što je zlatni standard?' => [$chunk('Činjenice o gliomu > **Dijagnoza **')],
        'Koliko košta Ferinject?' => [$chunk('Ferinject')],
    ]);

    $this->artisan('rag:evaluate-retrieval')
        ->expectsOutputToContain('Recall odlomaka')
        ->expectsOutputToContain('1 (Q49)')
        ->assertSuccessful();

    [$aggregation, $factual, $unanswerable] = storedRetrieval()['questions'];

    expect($aggregation)->toMatchArray(['passage_recall' => 0.5, 'reciprocal_rank' => 0.5, 'missing_passages' => ['REGORAFENIB']])
        ->and($factual)->toMatchArray(['passage_recall' => 1, 'reciprocal_rank' => 1, 'missing_passages' => []])
        ->and($unanswerable)->toMatchArray(['passage_recall' => null, 'reciprocal_rank' => null]);
});
