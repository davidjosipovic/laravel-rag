<?php

namespace App\Actions;

use App\Ai\Agents\Rag;
use App\Models\Chunk;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Responses\StructuredAgentResponse;

class AnswerQuestion
{
    /**
     * @return array{answer: string,conversationId: string, chunks: Collection<int, Chunk>, tokens_used: int}
     */
    public static function handle(string $question,User $user, ?string $conversationId=null): array
    {
        $agent = new Rag;

        if ($user->conversations()->where('id',$conversationId)->exists()){
        /** @var StructuredAgentResponse $response */
        $response = $agent->continue($conversationId,as:$user)->prompt($question);
        }
        else{
              /** @var StructuredAgentResponse $response */
        $response = $agent->forUser($user)->prompt($question);
        }
      

        $usage = $response->usage;

        return [
            'answer' => $response['value'],
            'conversation_id'=>$response->conversationId,
            'chunks' => $agent->retrievedChunks->unique('id')->values(),
            'tokens_used' => $usage->promptTokens + $usage->completionTokens,
        ];
    }
}
