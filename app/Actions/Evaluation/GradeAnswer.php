<?php

namespace App\Actions\Evaluation;

use App\Ai\Agents\AnswerJudge;
use App\Ai\Agents\Rag;
use App\Ai\Answer;
use App\Ai\EvaluationQuestion;
use App\Enums\EvaluationGrade;
use App\Models\Chunk;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

class GradeAnswer
{
    /**
     * Grade an answer against the expected answer.
     *
     * Refusals are graded in code, because whether the system refused is not a judgement call.
     * Everything else goes to the judge together with the passages the answer was based on,
     * so it can tell claims taken from the passages apart from made up ones. Grades are cached,
     * so an answer that comes out the same in another run is graded once and graded the same.
     *
     * @return array{grade: EvaluationGrade, note: string}
     */
    public function handle(EvaluationQuestion $question, Answer $answer): array
    {
        if ($answer->answer === Rag::NOT_AVAILABLE) {
            return $question->isUnanswerable()
                ? ['grade' => EvaluationGrade::Correct, 'note' => '']
                : ['grade' => EvaluationGrade::Refused, 'note' => 'Sustav kaže da informacija nije dostupna, a odgovor postoji u dokumentima.'];
        }

        $judge = new AnswerJudge;
        $prompt = $this->prompt($question, $answer);
        $provider = config()->string('ai.rag.judge.provider');
        $model = config('ai.rag.judge.model');
        $cacheKey = 'rag-evaluation:grade:'.sha1(implode("\n", [$provider, $model, $judge->instructions(), $prompt]));

        $cached = Cache::get($cacheKey);

        if (is_array($cached) && is_string($cached['grade'] ?? null) && is_string($cached['note'] ?? null)) {
            return ['grade' => EvaluationGrade::from($cached['grade']), 'note' => $cached['note']];
        }

        $response = $judge->prompt($prompt, provider: $provider, model: is_string($model) ? $model : null);

        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('The judge did not return a structured grade.');
        }

        $grade = $response->toArray();
        $grade = [
            'grade' => EvaluationGrade::from(is_string($grade['grade'] ?? null) ? $grade['grade'] : ''),
            'note' => is_string($grade['note'] ?? null) ? trim($grade['note']) : '',
        ];

        Cache::forever($cacheKey, ['grade' => $grade['grade']->value, 'note' => $grade['note']]);

        return $grade;
    }

    private function prompt(EvaluationQuestion $question, Answer $answer): string
    {
        $passages = $answer->chunks
            ->map(fn (Chunk $chunk, int $index): string => '['.($index + 1).'] '.$chunk->heading."\n".$chunk->content)
            ->implode("\n\n");

        return "Kategorija: {$question->category}\n\n".
            "Pitanje: {$question->question}\n\n".
            "Očekivani odgovor: {$question->expectedAnswer}\n\n".
            "Odgovor sustava: {$answer->answer}\n\n".
            'Odlomci koje je sustav koristio:'."\n".($passages === '' ? '(nema)' : $passages);
    }
}
