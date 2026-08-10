<?php
namespace App\Actions;

use App\Ai\Agents\Rag;
use App\Models\Chunk;
use Illuminate\Support\Str;
class  AnswerQuestion{

public static function handle(string $question):string{
    $chunks=Chunk::whereVectorSimilarTo('embedding',$question)
                    ->limit(10)
                    ->get();

    $agent=new Rag();
    $response = $agent->prompt($question,$chunks->pluck('content')->all());
    return json_decode($response->text,true)['value'];




}


}