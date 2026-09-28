<?php

namespace App\Ai\Tools;

use App\Actions\HybridSearch\RetrieveRelevantChunks;
use App\Models\Chunk;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Lets the agent search the knowledge base itself, instead of AnswerQuestion searching before every question.
 *
 * Not used at the moment: the local model does not reliably decide to call a tool on its own, so the search
 * always runs in code (see AnswerQuestion). It is kept so the tool call can be switched back on; how to wire
 * it into the Rag agent is described in the comment above Rag::__construct(). The tool call was removed in
 * commit ec57817.
 */
class SearchKnowledgeBase implements Tool
{
    /**
     * The chunks found by the last search, so they can be returned as the answer's sources.
     *
     * @var Collection<int, Chunk>
     */
    public Collection $retrievedChunks;

    public function __construct(private RetrieveRelevantChunks $retrieveRelevantChunks)
    {
        $this->retrievedChunks = new Collection;
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Pretraži bazu znanja o onkološkim lijekovima i terapijama. '.
            'Koristi za sva pitanja o lijekovima, nuspojavama, dozama i primjeni. '.
            'Upiši pitanje ili pojam prirodnim jezikom.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $this->retrievedChunks = $this->retrieveRelevantChunks->handle(trim((string) $request['query']));

        if ($this->retrievedChunks->isEmpty()) {
            return 'Nema rezultata u bazi znanja za taj upit.';
        }

        return $this->retrievedChunks
            ->map(fn (Chunk $chunk, int $index): string => '['.($index + 1).'] '.$chunk->document->title
                .($chunk->heading ? ' — '.$chunk->heading : '')."\n".$chunk->content)
            ->implode("\n\n");
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
        ];
    }
}
