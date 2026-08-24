<?php

namespace App\Ai\Agents;

use App\Models\Chunk;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\SimilaritySearch;

#[MaxSteps(5)]
class Rag implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable, RemembersConversations;

    /**
     * @var Collection<int, Chunk>
     */
    public Collection $retrievedChunks;

    public function __construct()
    {
        $this->retrievedChunks = new Collection;
    }

    public function instructions(): string
    {

        return 'Medicinski asistent. Koristi SimilaritySearch za medicinske činjenice. Ne daj osobne savjete. Uvijek preporuči liječnika i dodaj disclaimer. Hitno: pozovite 194.';
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            (new SimilaritySearch(using: function (string $query) {
                $chunks = Chunk::query()
                    ->whereVectorSimilarTo('embedding', $query, minSimilarity: 0.6)
                    ->limit(15)
                    ->get();

                $this->retrievedChunks = $this->retrievedChunks->merge($chunks);

                return $chunks;
            }))->withDescription('KRITIČNO: SimilaritySearch smiješ pozvati SAMO JEDNOM po upitu. Pretraži zdravstvenu bazu znanja. Koristi za pitanja o bolestima, lijekovima, simptomima, nuspojavama i liječenju. Ne koristi za osobne medicinske savjete, dijagnoze ili teme izvan medicine. Uvijek dodaj medicinski disclaimer i preporuči liječnika.'),
        ];
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
