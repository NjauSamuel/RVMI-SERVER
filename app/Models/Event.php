<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'event_date',
        'cover_media_item_id',
        'visibility',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
        ];
    }

    public function media(): HasMany
    {
        return $this->hasMany(MediaItem::class);
    }

    public function approvedMedia(): HasMany
    {
        return $this->media()->where('status', 'approved');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(EventComment::class)->latest();
    }

    public function coverMedia(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'cover_media_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPrivate(): bool
    {
        return $this->visibility === 'private';
    }

    public function assertVisibleTo(\App\Models\User $user): void
    {
        abort_if(
            $this->isPrivate() && ! $user->can('view-private-media'),
            403,
            'This event is private.',
        );
    }
}
