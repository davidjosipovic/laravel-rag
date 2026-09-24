<?php

namespace App\Actions\HybridSearch;

use App\Models\Chunk;

class FullTextSearch
{
    /**
     * Find chunks containing any of the query's terms, best matches first.
     *
     * Uses the `simple` text search configuration to match the chunks' full-text index. Terms are
     * combined with OR, since a natural-language question rarely has all of its words in one chunk,
     * and matched by prefix so different Croatian case endings of the same word still match.
     *
     * @return int[]
     */
    public function handle(string $query, int $limit): array
    {
        $tsQuery = $this->toTsQuery($query);

        if ($tsQuery === '') {
            return [];
        }

        return Chunk::query()
            ->whereRaw("to_tsvector('simple', content) @@ to_tsquery('simple', ?)", [$tsQuery])
            ->orderByRaw("ts_rank(to_tsvector('simple', content), to_tsquery('simple', ?)) desc", [$tsQuery])
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    /**
     * Build an OR-ed prefix tsquery, e.g. "docetaksela krvne" becomes "docetakse:* | krvn:*".
     */
    private function toTsQuery(string $query): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($words)
            ->filter(fn (string $word): bool => mb_strlen($word) >= 3)
            ->map(fn (string $word): string => $this->stem($word).':*')
            ->unique()
            ->implode(' | ');
    }

    /**
     * Strip a likely case ending so the prefix matches other forms of the word.
     */
    private function stem(string $word): string
    {
        $length = mb_strlen($word);

        return match (true) {
            $length >= 6 => mb_substr($word, 0, $length - 2),
            $length === 5 => mb_substr($word, 0, 4),
            default => $word,
        };
    }
}
