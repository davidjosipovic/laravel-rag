<?php

namespace App\Http\Controllers\Api;

use App\Actions\AnswerQuestion;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChatRequest;
use App\Http\Resources\AnswerResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

class ChatController extends Controller
{
    public function chat(ChatRequest $request): AnswerResource
    {
        $validated = $request->validated();

        $question = $validated['question'];
        $conversationId = $validated['conversation_id'] ?? null;
        $result = AnswerQuestion::handle($question, $request->user(), $conversationId);

        return new AnswerResource($result);

    }

    /** @return Collection<int, Conversation> */
    public function list(Request $request): Collection
    {
        return $request->user()->conversations()->get();
    }

    /** @return Collection<int, ConversationMessage> */
    public function history(Request $request, string $conversationId): Collection
    {
        $response = ConversationMessage::where('conversation_id', $conversationId)->get(['role', 'content']);

        return $response;
    }

    public function delete(Request $request, Conversation $conversation): JsonResponse
    {
        if ($request->user()->id !== $conversation['participant_id']) {
            return response()->json('Not authorized');
        }
        $conversation->delete();

        return response()->json('Deleted', 200);

    }
}
