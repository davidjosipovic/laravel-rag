<?php

namespace App\Ai;

use App\Models\Chunk;
use Illuminate\Support\Collection;

readonly class Answer
{
    /**
     * @param  Collection<int, Chunk>  $chunks
     */
    public function __construct(
        public string $answer,
        public ?string $conversationId,
        public Collection $chunks,
        public int $tokensUsed,
    ) {}
}
