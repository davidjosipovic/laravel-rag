<?php

namespace App\Http\Controllers\Api;

use App\Actions\AnswerQuestion;
use App\Http\Controllers\Controller;
use App\Http\Resources\AnswerResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

class ChatController extends Controller
{
    public function chat(Request $request): AnswerResource
    {
        $question = $request->input('question');
        $conversationId = $request->input('conversation_id') ?: null;
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

    public function delete(Request $request, string $conversationId): JsonResponse
    {
        $deleted = Conversation::destroy($conversationId);
        if ($deleted === 1) {
            return response()->json('Chat deleted', 200);
        } else {
            return response()->json('Chat not found', 404);

        }
    }
}
