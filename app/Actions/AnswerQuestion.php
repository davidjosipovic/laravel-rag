<?php

namespace App\Actions;

use App\Ai\Agents\Rag;
use App\Models\Chunk;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Responses\StructuredAgentResponse;

class AnswerQuestion
{
    public function __construct(private Rag $agent) {}

    /**
     * @return array{answer: string, conversation_id: string, chunks: Collection<int, Chunk>, tokens_used: int}
     */
    public function handle(string $question, User $user, ?string $conversationId = null): array
    {

        if ($user->conversations()->where('id', $conversationId)->exists()) {
            /** @var StructuredAgentResponse $response */
            $response = $this->agent->continue($conversationId, as: $user)->prompt($question);
        } else {
            /** @var StructuredAgentResponse $response */
            $response = $this->agent->forUser($user)->prompt($question);
        }

        $usage = $response->usage;

        return [
            'answer' => (string) $response['value'],
            'conversation_id' => $response->conversationId,
            'chunks' => $this->agent->retrievedChunks()->unique('id')->values(),
            'tokens_used' => $usage->promptTokens + $usage->completionTokens,
        ];
    }
}
