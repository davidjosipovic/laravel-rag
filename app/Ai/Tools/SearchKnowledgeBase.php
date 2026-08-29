<?php

namespace App\Ai\Tools;

use App\Actions\HybridSearch\FullTextSearch;
use App\Actions\HybridSearch\SimilaritySearch;
use App\Models\Chunk;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchKnowledgeBase implements Tool
{
    /**
     * Summary of retrievedChunks
     *
     * @var Collection<int,Chunk>
     */
    public Collection $retrievedChunks;

    public function __construct(private FullTextSearch $fullTextSearch, private SimilaritySearch $similaritySearch)
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
        $query = trim((string) $request['query']);

        $ids = $this->fuse([
            $this->fullTextSearch->handle($query, 50),
            $this->similaritySearch->handle($query, 50),
        ]);

        if (empty($ids)) {
            return 'Nema rezultata u bazi znanja za taj upit.';
        }

        $chunks = Chunk::with('document:id,title')->whereIn('id', $ids)
            ->get()
            ->rerank(by: 'content', query: $query, limit: 8);

        $this->retrievedChunks = $chunks;

        return $chunks
            ->map(fn ($c) => "[chunk {$c->id}]\n{$c->content}")
            ->implode("\n\n");
    }

    /**
     * @param  array<int, int[]>  $lists
     * @return int[]
     */
    private function fuse(array $lists, int $k = 60, int $limit = 30): array
    {
        $scores = [];

        foreach ($lists as $list) {
            foreach (array_values($list) as $rank => $id) {
                $scores[$id] = ($scores[$id] ?? 0) + 1 / ($k + $rank + 1);
            }
        }

        arsort($scores);

        return array_slice(array_keys($scores), 0, $limit);
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
