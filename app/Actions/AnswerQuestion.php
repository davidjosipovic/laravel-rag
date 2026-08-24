<?php

namespace App\Actions;

use App\Ai\Agents\Rag;
use App\Models\Chunk;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Responses\StructuredAgentResponse;

class AnswerQuestion
{
    /**
     * @return array{answer: string, chunks: Collection<int, Chunk>, tokens_used: int}
     */
    public static function handle(string $question): array
    {
        $agent = new Rag;

        /** @var StructuredAgentResponse $response */
        $response = $agent->prompt($question);

        $usage = $response->usage;

        return [
            'answer' => $response['value'],
            'chunks' => $agent->retrievedChunks->unique('id')->values(),
            'tokens_used' => $usage->promptTokens + $usage->completionTokens,
        ];
    }
}
