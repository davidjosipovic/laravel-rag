<?php

namespace App\Http\Controllers\Api;

use App\Actions\AnswerQuestion;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChatRequest;
use App\Http\Resources\AnswerResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

class ChatController extends Controller
{
    public function chat(ChatRequest $request, AnswerQuestion $answerQuestion): AnswerResource
    {
        $validated = $request->validated();

        $question = $validated['question'];
        $conversationId = $validated['conversation_id'] ?? null;
        $result = $answerQuestion->handle($question, $request->user(), $conversationId);

        return new AnswerResource($result);

    }

    /** @return Collection<int, Conversation> */
    public function list(Request $request): Collection
    {
        Log::info("hello bro");
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
