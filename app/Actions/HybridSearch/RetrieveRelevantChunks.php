<?php

namespace App\Actions\HybridSearch;

use App\Models\Chunk;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Reranking;

class RetrieveRelevantChunks
{
    public function __construct(
        private FullTextSearch $fullTextSearch,
        private SimilaritySearch $similaritySearch,
    ) {}

    /**
     * Find the chunks that answer the query, most relevant first.
     *
     * Full-text and vector search results are fused with reciprocal rank fusion, then reranked,
     * and only chunks the reranker considers relevant are kept.
     *
     * @return Collection<int, Chunk>
     */
    public function handle(string $query, int $limit = 8): Collection
    {
        $ids = $this->fuse([
            $this->fullTextSearch->handle($query, 50),
            $this->similaritySearch->handle($query, 50),
        ]);

        if ($ids === []) {
            return new Collection;
        }

        $candidates = Chunk::with('document:id,title')->whereIn('id', $ids)->get()->values();

        $ranking = Reranking::of($candidates->pluck('content')->all())
            ->limit($limit)
            ->rerank($query);

        return new Collection(
            collect($ranking->results)
                ->filter(fn ($result): bool => $result->score >= $this->minRelevance())
                ->map(fn ($result): Chunk => $candidates[$result->index])
                ->values()
                ->all()
        );
    }

    /**
     * Reranker scores below this are treated as unrelated to the question. The scale depends on
     * the reranker, so the threshold is configured per setup and calibrated on the test set.
     */
    public function minRelevance(): float
    {
        return config()->float('ai.rag.min_relevance');
    }

    /**
     * Merge ranked id lists with reciprocal rank fusion.
     *
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
}
