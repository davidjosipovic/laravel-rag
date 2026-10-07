<?php

namespace App\Ai\Middleware;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Gemma's chat template sometimes leaks its control tokens into the answer, e.g. "<|channel>thought <channel|>"
 * before the actual reply, even with thinking disabled on the server. They are removed before the answer is
 * returned and stored in the conversation.
 */
class StripControlTokens
{
    /**
     * Handle the incoming prompt.
     */
    public function handle(AgentPrompt $prompt, Closure $next): AgentResponse
    {
        return $next($prompt)->then(function (AgentResponse $response): void {
            $response->text = $this->strip($response->text);
        });
    }

    /**
     * Remove a leaked thought channel with its content, then any remaining control tokens such as "<|channel>".
     */
    public function strip(string $text): string
    {
        $text = preg_replace('/<\|channel>.*?<channel\|>/s', '', $text);

        return trim(preg_replace('/<\|[^>]*>|<[a-z_]+\|>/', '', $text));
    }
}
