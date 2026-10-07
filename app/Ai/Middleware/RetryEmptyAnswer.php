<?php

namespace App\Ai\Middleware;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

/**
 * The local model occasionally returns no text at all after searching, at random and for different questions
 * in different runs. Asking once more gives an answer, so the user is never left without one. The retry happens
 * before the conversation is stored, so only the second answer is saved.
 */
class RetryEmptyAnswer
{
    /**
     * Handle the incoming prompt.
     */
    public function handle(AgentPrompt $prompt, Closure $next): AgentResponse
    {
        $response = $next($prompt);

        if (trim($response->text) === '') {
            $response = $next($prompt);
        }

        return $response;
    }
}
