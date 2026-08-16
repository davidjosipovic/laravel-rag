<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnswerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        return [
            'answer' => $this->resource['answer'],
            'sources' => collect($this->resource['chunks'])->map(fn ($chunk) => [
                'document_id' => $chunk->document_id,
                'document_title' => $chunk->document->title,
                'excerpt' => str($chunk->content)->limit(200)->toString(),
            ])->all(),
            'tokens_used' => $this->resource['tokens_used'] ?? null,
        ];
    }
}
