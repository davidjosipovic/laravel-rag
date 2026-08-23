<?php

namespace App\Actions;

use App\Ai\Agents\Rag;
use App\Models\Chunk;
use Illuminate\Database\Eloquent\Collection;

class AnswerQuestion
{
    /**
     * @return array{answer: string, chunks: Collection<int, Chunk>, tokens_used: int}
     */
    public static function handle(string $question, int $top_k = 5): array
    {
        $chunks = Chunk::whereVectorSimilarTo('embedding', $question)
            ->limit($top_k)
            ->with('document')
            ->get()
            ->rerank('content',$question);

        $agent = new Rag($chunks);

        $response = $agent->prompt($question);

        $decoded = json_decode($response->text, true);

        $usage = $response->usage;
        $tokensUsed = $usage->promptTokens + $usage->completionTokens;

        return [
            'answer' => $decoded['value'] ?? $response->text,
            'chunks' => $chunks,
            'tokens_used' => $tokensUsed,
        ];
    }
}
