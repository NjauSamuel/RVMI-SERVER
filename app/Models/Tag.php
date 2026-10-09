<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tag extends Model
{
    protected $fillable = ['name', 'kind', 'person_id'];

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaItem::class, 'media_tag')
            ->withPivot(['source', 'confidence'])
            ->withTimestamps();
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function favoritedBy(): HasMany
    {
        return $this->hasMany(UserTagFavorite::class);
    }

    public function isPerson(): bool
    {
        return $this->kind === 'person';
    }
}
