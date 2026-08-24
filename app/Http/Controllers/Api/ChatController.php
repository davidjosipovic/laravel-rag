<?php

namespace App\Http\Controllers\Api;

use App\Actions\AnswerQuestion;
use App\Ai\Agents\Rag;
use App\Http\Controllers\Controller;
use App\Http\Resources\AnswerResource;
use Illuminate\Http\Request;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

class ChatController extends Controller
{
   

    public function chat(Request $request)
    {
        $question=$request->input('question');
        $conversationId=$request->input('conversation_id') ?: null;
        $result = AnswerQuestion::handle($question,$request->user(),$conversationId);

        return new AnswerResource($result);

    }

    public function list(Request $request)
    {
        return $request->user()->conversations()->get();
    }

    public function history(Request $request,string $conversationId)
    {   
        $response=ConversationMessage::where('conversation_id',$conversationId)->get(['role','content']);

        return $response;
    }

    public function delete(Request $request,string $conversationId)
    {
        $deleted=Conversation::destroy($conversationId);
        if($deleted===1){
            return response()->json("Chat deleted",200);
        }
        else{
             return response()->json("Chat not found",404);

        }
    }
}
