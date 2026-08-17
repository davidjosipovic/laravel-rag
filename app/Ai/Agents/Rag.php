<?php

namespace App\Ai\Agents;

use App\Models\Chunk;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

class Rag implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;

    /**
     * @param  Collection<int, Chunk>  $chunks
     */
    public function __construct(protected Collection $chunks) {}

    public function instructions(): string
    {
        $context = $this->chunks->pluck('content')->implode("\n\n---\n\n");

        return "Answer only using the following context. If the answer isn't in it, say you don't know.\n\nContext:\n{$context}";
    }

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return Message[]
     */
    public function messages(): iterable
    {
        return [];
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [];
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'value' => $schema->string()->required(),
        ];
    }
}
