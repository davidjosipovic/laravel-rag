<?php

namespace App\Ai\Agents;

use App\Ai\Tools\SearchKnowledgeBase;
use App\Models\Chunk;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

#[MaxSteps(5)]
class Rag implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(private SearchKnowledgeBase $search) {}

    public function instructions(): string
    {
        return 'Medicinski asistent. Pretražuj bazu znanja prije odgovora. '.
            'SimilaritySearch za semantička pitanja (simptomi, opisi stanja). '.
            'KeywordSearch za točne pojmove (nazivi lijekova, kratice, šifre). '.
            'Kod složenijih pitanja koristi oba. Odgovaraj samo na temelju pronađenog sadržaja. '.
            'Ne daj osobne savjete ni dijagnoze. Uvijek preporuči liječnika i dodaj disclaimer. '.
            'Hitno: pozovite 194.';
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            $this->search,
        ];
    }

    /**
     * @return Collection<int, Chunk>
     */
    public function retrievedChunks(): Collection
    {
        return $this->search->retrievedChunks;
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
