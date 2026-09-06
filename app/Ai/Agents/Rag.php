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
        return 'Farmaceutski asistent specijaliziran za onkološke lijekove i terapije. '.
         'Prije svakog odgovora pretraži bazu znanja pomoću dostupnog alata za pretraživanje. '.
         'Alat sam kombinira semantičku i pojmovnu pretragu — dovoljno je postaviti upit prirodnim jezikom, '.
         'ne treba birati vrstu pretrage. '.
         'Odgovaraj isključivo na temelju sadržaja koji alat vrati. '.
         'Ako alat ne pronađe ništa relevantno, jasno reci da ta informacija nije dostupna u bazi znanja — ne nagađaj. '.
         'Ne daj osobne savjete ni dijagnoze. Uvijek preporuči liječnika ili ljekarnika i dodaj disclaimer. '.
         'Hitno: pozovite 112.';
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
