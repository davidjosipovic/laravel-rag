<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Observers\DocumentObserver;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable(['content', 'mime_type', 'metadata', 'title', 'status'])]
#[ObservedBy(DocumentObserver::class)]
class Document extends Model implements HasMedia
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, InteractsWithMedia;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'status' => DocumentStatus::class,
        ];
    }

    /**
     * @return HasMany<Chunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }
}
