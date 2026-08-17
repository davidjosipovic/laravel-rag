<?php

namespace App\Http\Resources;

use App\Models\Chunk;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnswerResource extends JsonResource
{
    /**
     * @var array{answer: string, chunks: Collection<int, Chunk>, tokens_used: int}
     */
    public $resource;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        return [
            'answer' => $this->resource['answer'],
            'sources' => $this->resource['chunks']->map(fn (Chunk $chunk) => [
                'document_id' => $chunk->document_id,
                'document_title' => $chunk->document->title,
                'excerpt' => str($chunk->content)->limit(200)->toString(),
            ])->all(),
            'tokens_used' => $this->resource['tokens_used'],
        ];
    }
}
