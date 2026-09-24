<?php

namespace App\Http\Controllers\Api;

use App\Actions\AnswerQuestion;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChatRequest;
use App\Http\Resources\AnswerResource;
use App\Http\Resources\ConversationMessageResource;
use App\Http\Resources\ConversationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Models\Conversation;

class ChatController extends Controller
{
    /**
     * Ask a question.
     *
     * Answers the question using the knowledge base and returns the source documents it was based on.
     * Pass `conversation_id` to continue an existing conversation; otherwise a new one is started.
     */
    public function chat(ChatRequest $request, AnswerQuestion $answerQuestion): AnswerResource
    {
        $validated = $request->validated();

        $question = $validated['question'];
        $conversationId = $validated['conversation_id'] ?? null;
        $result = $answerQuestion->handle($question, $request->user(), $conversationId);

        return new AnswerResource($result);
    }

    /**
     * List conversations.
     *
     * Returns the authenticated user's conversations, most recently updated first.
     */
    public function list(Request $request): AnonymousResourceCollection
    {
        $conversations = $request->user()->conversations()->latest('updated_at')->get();

        return ConversationResource::collection($conversations);
    }

    /**
     * Get conversation history.
     *
     * Returns the messages of a conversation in chronological order.
     */
    public function history(Conversation $conversation): AnonymousResourceCollection
    {
        Gate::authorize('view', $conversation);

        $messages = $conversation->messages()->oldest()->get();

        return ConversationMessageResource::collection($messages);
    }

    /**
     * Delete a conversation.
     */
    public function delete(Conversation $conversation): Response
    {
        Gate::authorize('delete', $conversation);

        $conversation->delete();

        return response()->noContent();
    }
}
