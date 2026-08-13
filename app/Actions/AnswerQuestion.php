<?php

namespace App\Actions;

use App\Ai\Agents\Rag;
use App\Models\Chunk;

class AnswerQuestion
{
    public static function handle(string $question, int $top_k = 5): array
    {
        $chunks = Chunk::whereVectorSimilarTo('embedding', $question)
            ->limit($top_k)
            ->get();

        $agent = new Rag();
        $response = $agent->prompt($question, $chunks->pluck('content')->all());

        $decoded = json_decode($response->text, true);

        $usage = $response->usage;
$tokensUsed = $usage ? $usage->promptTokens + $usage->completionTokens : null;

return [
    'answer' => $decoded['value'] ?? $response->text,
    'chunks' => $chunks,
    'tokens_used' => $tokensUsed,
];
    }
}