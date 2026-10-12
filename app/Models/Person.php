<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends Model
{
    protected $fillable = ['name', 'indexed_in_rekognition', 'reference_face_count', 'tag_id'];

    protected function casts(): array
    {
        return [
            'indexed_in_rekognition' => 'boolean',
            'reference_face_count' => 'integer',
        ];
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function referencePhotos(): HasMany
    {
        return $this->hasMany(PersonReferencePhoto::class);
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
