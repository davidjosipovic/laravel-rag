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
 * Lets the agent search the knowledge base with hybrid search.
 */
class SearchKnowledgeBase implements Tool
{
    /**
     * The chunks found by all searches for the current question, so they can be returned as the answer's sources.
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
        return 'Search the knowledge base of oncology documents for passages that answer the user\'s message.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $chunks = $this->retrieveRelevantChunks->handle(trim((string) $request['query']));

        $this->retrievedChunks = $this->retrievedChunks->merge($chunks);

        if ($chunks->isEmpty()) {
            return 'No results in the knowledge base for this query.';
        }

        return $chunks
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
            'query' => $schema->string()
                ->description('The user\'s question in full, in Croatian, as they wrote it, not shortened to keywords. '.
                    'For a follow-up, add what it refers to from the conversation, e.g. "a koje su nuspojave?" '.
                    'becomes "Koje su nuspojave docetaksela?".')
                ->required(),
        ];
    }
}
