<?php

namespace App\Actions;

use App\Actions\HybridSearch\RetrieveRelevantChunks;
use App\Ai\Agents\Rag;
use App\Ai\Answer;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\AgentResponse;

class AnswerQuestion
{
    public function __construct(private RetrieveRelevantChunks $retrieveRelevantChunks) {}

    /**
     * Search the knowledge base for the question and let the agent answer from the results.
     *
     * The search always runs in code rather than as an agent tool, because the local model
     * does not reliably decide to call a tool on its own.
     */
    public function handle(string $question, User $user, ?string $conversationId = null): Answer
    {
        $chunks = $this->retrieveRelevantChunks->handle($this->searchQuery($question, $conversationId));

        $agent = new Rag($chunks);

        if ($conversationId !== null) {
            /** @var AgentResponse $response */
            $response = $agent->continue($conversationId, as: $user)->prompt($question);
        } else {
            /** @var AgentResponse $response */
            $response = $agent->forUser($user)->prompt($question);
        }

        $reply = $agent->reply($response->text);

        $this->storeReply($response->conversationId, $reply ?? Rag::NOT_AVAILABLE);

        $usage = $response->usage;

        return new Answer(
            answer: $reply ?? Rag::NOT_AVAILABLE,
            conversationId: $response->conversationId,
            chunks: $reply === null ? new Collection : $chunks,
            tokensUsed: $usage->promptTokens + $usage->completionTokens,
        );
    }

    /**
     * Prefix follow-up questions with the previous question, so that e.g. "a koje su nuspojave?"
     * is still searched in the context of the medicine asked about before.
     */
    private function searchQuery(string $question, ?string $conversationId): string
    {
        if ($conversationId === null) {
            return $question;
        }

        $previousQuestion = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'user')
            ->latest()
            ->orderByDesc('id')
            ->value('content');

        return trim($previousQuestion."\n".$question);
    }

    /**
     * The conversation store saves the model's raw output; replace it with the cleaned-up reply the
     * user saw, so the history endpoint and follow-up questions work with the actual answer.
     */
    private function storeReply(?string $conversationId, string $reply): void
    {
        if ($conversationId === null) {
            return;
        }

        ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->latest()
            ->orderByDesc('id')
            ->limit(1)
            ->update(['content' => $reply]);
    }
}
