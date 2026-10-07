<?php

namespace App\Actions;

use App\Ai\Agents\Rag;
use App\Ai\Answer;
use App\Models\User;
use Laravel\Ai\Responses\AgentResponse;

class AnswerQuestion
{
    /**
     * Let the agent answer the question, searching the knowledge base with its tool. Every question
     * gets a new agent, so the sources of a previous question are never returned again.
     */
    public function handle(string $question, User $user, ?string $conversationId = null): Answer
    {
        $agent = app(Rag::class);

        if ($conversationId !== null) {
            /** @var AgentResponse $response */
            $response = $agent->continue($conversationId, as: $user)->prompt($question);
        } else {
            /** @var AgentResponse $response */
            $response = $agent->forUser($user)->prompt($question);
        }

        $usage = $response->usage;

        return new Answer(
            answer: $response->text,
            conversationId: $response->conversationId,
            chunks: $agent->retrievedChunks()->values(),
            tokensUsed: $usage->promptTokens + $usage->completionTokens,
        );
    }
}
