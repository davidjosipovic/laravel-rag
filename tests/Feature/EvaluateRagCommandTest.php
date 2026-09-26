<?php

use App\Actions\AnswerQuestion;
use App\Ai\Agents\AnswerJudge;
use App\Ai\Agents\Rag;
use App\Ai\Answer;
use App\Models\Chunk;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake();
    Process::fake(['git rev-parse *' => Process::result('abc1234')]);
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
function storedResults(): array
{
    $files = Storage::disk('local')->files('evaluation/results');

    return json_decode(Storage::disk('local')->get(end($files)), true);
}

test('every question is answered and graded in each run at temperature 0', function () {
    fakeAnswers([
        'Koliko prije docetaksela treba izvaditi krvne nalaze?' => ['Najviše 72 sata prije terapije.', 'Najviše 72 sata.'],
        'Koliko košta Ferinject?' => Rag::NOT_AVAILABLE,
    ]);
    AnswerJudge::fake([['grade' => 'tocno', 'note' => ''], ['grade' => 'djelomicno', 'note' => 'Nedostaje KKS.']]);

    $this->artisan('rag:evaluate', ['--runs' => 2, '--label' => 'Headings in context'])
        ->expectsOutputToContain('Točnost (prosjek)')
        ->assertSuccessful();

    $results = storedResults();

    expect(config('ai.rag.temperature'))->toBe(0.0)
        ->and($results)->toMatchArray(['label' => 'Headings in context', 'commit' => 'abc1234', 'temperature' => 0.0, 'runs' => 2])
        ->and(array_column($results['questions'][0]['runs'], 'grade'))->toBe(['tocno', 'djelomicno'])
        ->and(array_column($results['questions'][1]['runs'], 'grade'))->toBe(['tocno', 'tocno'])
        ->and($results['questions'][0]['runs'][0]['sources'])->toContain(Chunk::first()->document->title.' — Docetaksel');

    AnswerJudge::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Očekivani odgovor: Najviše 72 sata prije terapije.')
        && $prompt->contains("[1] Docetaksel\nKrvne nalaze uzorkovati najviše 72 sata prije terapije."));
});

test('refusing a question that has an answer is graded as refused without asking the judge', function () {
    fakeAnswers([
        'Koliko prije docetaksela treba izvaditi krvne nalaze?' => Rag::NOT_AVAILABLE,
        'Koliko košta Ferinject?' => Rag::NOT_AVAILABLE,
    ]);
    AnswerJudge::fake()->preventStrayPrompts();

    $this->artisan('rag:evaluate', ['--runs' => 1])->assertSuccessful();

    expect(storedResults()['questions'][0]['runs'][0]['grade'])->toBe('odbijeno');
    AnswerJudge::assertNeverPrompted();
});

test('only the grading is retried when the judge is overloaded', function () {
    $chunk = Chunk::factory()->create();
    $this->mock(AnswerQuestion::class)
        ->shouldReceive('handle')
        ->once()
        ->andReturn(new Answer('Najviše 72 sata prije terapije.', null, new Collection([$chunk]), 0));

    $calls = 0;
    AnswerJudge::fake(function () use (&$calls): array {
        if (++$calls === 1) {
            throw new RuntimeException('AI provider [gemini] is overloaded.');
        }

        return ['grade' => 'tocno', 'note' => ''];
    });

    $this->artisan('rag:evaluate', ['--runs' => 1, '--only' => 'Q01'])->assertSuccessful();

    expect(storedResults()['questions'][0]['runs'][0]['grade'])->toBe('tocno');
    Sleep::assertSleptTimes(1);
});

test('the evaluation stops when the judge stays unavailable', function () {
    fakeAnswers(['Koliko prije docetaksela treba izvaditi krvne nalaze?' => 'Najviše 72 sata.']);
    AnswerJudge::fake(fn () => throw new RuntimeException('Application rate limited by AI provider [groq].'));

    $this->artisan('rag:evaluate', ['--runs' => 1, '--only' => 'Q01']);
})->throws(RuntimeException::class, 'The judge is not available');

test('the evaluation stops when answering stays rate limited', function () {
    $this->mock(AnswerQuestion::class)
        ->shouldReceive('handle')
        ->times(3)
        ->andThrow(new RuntimeException('Application rate limited by AI provider [cohere].'));

    $this->artisan('rag:evaluate', ['--runs' => 1, '--only' => 'Q01']);
})->throws(RuntimeException::class, 'Answering is not available');

test('an answer that comes out the same again is graded once', function () {
    fakeAnswers(['Koliko prije docetaksela treba izvaditi krvne nalaze?' => 'Najviše 72 sata prije terapije.']);
    AnswerJudge::fake([['grade' => 'tocno', 'note' => '']])->preventStrayPrompts();

    $this->artisan('rag:evaluate', ['--runs' => 3, '--only' => 'Q01'])->assertSuccessful();

    expect(array_column(storedResults()['questions'][0]['runs'], 'grade'))->toBe(['tocno', 'tocno', 'tocno']);
});

test('only the selected questions are evaluated', function () {
    fakeAnswers(['Koliko košta Ferinject?' => Rag::NOT_AVAILABLE]);

    $this->artisan('rag:evaluate', ['--runs' => 1, '--only' => 'Q64'])->assertSuccessful();

    expect(array_column(storedResults()['questions'], 'id'))->toBe(['Q64']);
});

test('changes against the previous results are listed, with ties going to the worse grade', function () {
    Storage::disk('local')->put('evaluation/results/2026-09-01_120000_baseline.json', json_encode([
        'label' => 'baseline',
        'questions' => [
            ['id' => 'Q01', 'runs' => [['grade' => 'tocno'], ['grade' => 'tocno']]],
            ['id' => 'Q64', 'runs' => [['grade' => 'netocno'], ['grade' => 'netocno']]],
        ],
    ]));
    fakeAnswers([
        'Koliko prije docetaksela treba izvaditi krvne nalaze?' => ['Najviše 72 sata.', 'Najviše 48 sati.'],
        'Koliko košta Ferinject?' => Rag::NOT_AVAILABLE,
    ]);
    AnswerJudge::fake([['grade' => 'tocno', 'note' => ''], ['grade' => 'netocno', 'note' => 'Izmišljeno.']]);

    $this->artisan('rag:evaluate', ['--runs' => 2, '--compare' => 'latest'])
        ->expectsOutputToContain('Usporedba s baseline')
        ->expectsTable(['Pitanje', 'Prije', 'Sad', ''], [
            ['Q01', 'Točno', 'Netočno', 'lošije'],
            ['Q64', 'Netočno', 'Točno', 'bolje'],
        ])
        ->assertSuccessful();
});

test('a missing questions file fails with a clear message', function () {
    Storage::disk('local')->delete('evaluation/questions.csv');

    $this->artisan('rag:evaluate')->assertFailed();
})->throws(RuntimeException::class, 'Cannot read the questions file');
