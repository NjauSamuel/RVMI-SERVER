<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Person extends Model
{
    protected $fillable = ['name', 'indexed_in_rekognition', 'tag_id'];

    protected function casts(): array
    {
        return [
            'indexed_in_rekognition' => 'boolean',
        ];
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    /**
     * The ExternalImageId used in the Rekognition Face Collection for this
     * person - letters, numbers, . : _ - only, so we just use the numeric id.
     */
    public function rekognitionExternalId(): string
    {
        return (string) $this->id;
    }
}
