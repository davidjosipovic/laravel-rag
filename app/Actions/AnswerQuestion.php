<?php

namespace App\Actions;

use App\Ai\Agents\Rag;
use App\Ai\Answer;
use App\Models\User;
use Laravel\Ai\Responses\AgentResponse;

class AnswerQuestion
{
    public function __construct(private Rag $agent) {}

    public function handle(string $question, User $user, ?string $conversationId = null): Answer
    {

        if ($conversationId !== null) {
            /** @var AgentResponse $response */
            $response = $this->agent->continue($conversationId, as: $user)->prompt($question);
        } else {
            /** @var AgentResponse $response */
            $response = $this->agent->forUser($user)->prompt($question);
        }

        $usage = $response->usage;

        return new Answer(
            answer: $response->text,
            conversationId: $response->conversationId,
            chunks: $this->agent->retrievedChunks()->values(),
            tokensUsed: $usage->promptTokens + $usage->completionTokens,
        );
    }
}
