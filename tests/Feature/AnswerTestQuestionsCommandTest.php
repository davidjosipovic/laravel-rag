<?php

use App\Actions\AnswerQuestion;
use App\Ai\Agents\Rag;
use App\Ai\Answer;
use App\Models\Chunk;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake();
    Process::fake(['git describe *' => Process::result('abc1234')]);
    User::factory()->create();

    Storage::disk('local')->put('evaluation/questions.csv', implode("\n", [
        "\u{FEFF}id,kategorija,pitanje,ocekivani_odgovor,izvor",
        'Q01,cinjenicno,Koliko prije docetaksela treba izvaditi krvne nalaze?,Najviše 72 sata prije terapije.,AI_lijekovi.docx',
        'Q64,neodgovorivo,Koliko košta Ferinject?,Nije navedeno u dokumentima.,',
    ]));
});

/**
 * @param  array<string, string|list<string>>  $answers  Answer per question, or one answer per run.
 */
function fakeAnswers(array $answers): void
{
    $chunk = Chunk::factory()->create(['heading' => 'Docetaksel', 'content' => 'Krvne nalaze uzorkovati najviše 72 sata prije terapije.']);
    $asked = [];

    test()->mock(AnswerQuestion::class)
        ->shouldReceive('handle')
        ->andReturnUsing(function (string $question) use ($answers, $chunk, &$asked): Answer {
            $answer = (array) $answers[$question];
            $answer = $answer[($asked[$question] = ($asked[$question] ?? -1) + 1) % count($answer)];

            return new Answer(
                answer: $answer,
                conversationId: null,
                chunks: $answer === Rag::NOT_AVAILABLE ? new Collection : new Collection([$chunk]),
                tokensUsed: 0,
            );
        });
}

/**
 * @return array<string, mixed>
 */
function storedAnswers(): array
{
    $files = Storage::disk('local')->files('evaluation/answers');

    return json_decode(Storage::disk('local')->get(end($files)), true);
}

test('every question is answered in each run at temperature 0 and stored with its passages', function () {
    fakeAnswers([
        'Koliko prije docetaksela treba izvaditi krvne nalaze?' => ['Najviše 72 sata prije terapije.', 'Najviše 72 sata.'],
        'Koliko košta Ferinject?' => Rag::NOT_AVAILABLE,
    ]);

    $this->artisan('rag:answer-test-questions', ['--runs' => 2, '--label' => 'Headings in context'])
        ->expectsOutputToContain('Odbijeno, a odgovor postoji')
        ->assertSuccessful();

    $results = storedAnswers();

    expect(config('ai.rag.temperature'))->toBe(0.0)
        ->and($results)->toMatchArray(['label' => 'Headings in context', 'commit' => 'abc1234', 'temperature' => 0.0, 'runs' => 2])
        ->and($results)->not->toHaveKey('judge')
        ->and($results['questions'][0])->toMatchArray(['id' => 'Q01', 'expected_answer' => 'Najviše 72 sata prije terapije.', 'source' => 'AI_lijekovi.docx'])
        ->and(array_column($results['questions'][0]['runs'], 'answer'))->toBe(['Najviše 72 sata prije terapije.', 'Najviše 72 sata.'])
        ->and(array_column($results['questions'][1]['runs'], 'refused'))->toBe([true, true])
        ->and($results['questions'][0]['runs'][0]['passages'][0])->toBe([
            'document' => Chunk::first()->document->title,
            'heading' => 'Docetaksel',
            'content' => 'Krvne nalaze uzorkovati najviše 72 sata prije terapije.',
        ]);
});

test('refusing an answerable question and answering an unanswerable one are listed', function () {
    fakeAnswers([
        'Koliko prije docetaksela treba izvaditi krvne nalaze?' => Rag::NOT_AVAILABLE,
        'Koliko košta Ferinject?' => 'Ferinject košta 100 eura.',
    ]);

    $this->artisan('rag:answer-test-questions')
        ->expectsOutputToContain('1 (Q01)')
        ->expectsOutputToContain('1 (Q64)')
        ->assertSuccessful();
});

test('an error answering one question is stored and the run continues', function () {
    $this->mock(AnswerQuestion::class)
        ->shouldReceive('handle')
        ->andThrow(new RuntimeException('Invalid response.'));

    $this->artisan('rag:answer-test-questions')->assertSuccessful();

    expect(array_column(array_merge(...array_column(storedAnswers()['questions'], 'runs')), 'error'))
        ->toBe(['Invalid response.', 'Invalid response.']);
});

test('the run stops when answering stays rate limited', function () {
    $this->mock(AnswerQuestion::class)
        ->shouldReceive('handle')
        ->times(3)
        ->andThrow(new RuntimeException('Application rate limited by AI provider [cohere].'));

    $this->artisan('rag:answer-test-questions', ['--only' => 'Q01']);
})->throws(RuntimeException::class, 'Answering is not available');

test('only the selected questions are asked', function () {
    fakeAnswers(['Koliko košta Ferinject?' => Rag::NOT_AVAILABLE]);

    $this->artisan('rag:answer-test-questions', ['--only' => 'Q64'])->assertSuccessful();

    expect(array_column(storedAnswers()['questions'], 'id'))->toBe(['Q64']);
});

test('a missing questions file fails with a clear message', function () {
    Storage::disk('local')->delete('evaluation/questions.csv');

    $this->artisan('rag:answer-test-questions')->assertFailed();
})->throws(RuntimeException::class, 'Cannot read the questions file');

test('a question line with a wrong number of columns fails instead of being skipped', function () {
    Storage::disk('local')->put('evaluation/questions.csv', implode("\n", [
        'id,kategorija,pitanje,ocekivani_odgovor,izvor',
        'Q01,cinjenicno,Kada se vade nalazi?,Najviše 72 sata prije terapije.,AI_lijekovi.docx',
        'Q02,cinjenicno,Koja je premedikacija?,Deksametazon, famotidin.,AI_lijekovi.docx',
        '',
    ]));

    $this->artisan('rag:answer-test-questions');
})->throws(RuntimeException::class, 'Line 3');
