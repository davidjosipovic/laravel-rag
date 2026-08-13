<?php

namespace App\Http\Controllers\Api;

use App\Actions\AnswerQuestion;
use App\Http\Controllers\Controller;
use App\Http\Requests\AskRequest;
use App\Http\Resources\AnswerResource;
use Illuminate\Http\Request;

class QueryController extends Controller
{
    /**
     * Display a listing of the resource.
     */

    
    public function ask(AskRequest $request)
    {
        //
        $question=$request->validated('question');
        $top_k=$request->validated('top_k');
        $result=AnswerQuestion::handle($question, $top_k);
        return new AnswerResource($result);
    }

}
