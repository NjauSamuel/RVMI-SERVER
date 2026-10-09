<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaItem extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_STAGING = 'staging';

    public const STATUS_PENDING_PROCESSING = 'pending_processing';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'event_id',
        'type',
        's3_key',
        'thumbnail_key',
        'blurhash',
        'status',
        'processing_attempts',
        'visibility_override',
        'width',
        'height',
        'duration_seconds',
        'size_bytes',
        'uploaded_by',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'processing_attempts' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'media_tag')
            ->withPivot(['source', 'confidence'])
            ->withTimestamps();
    }

    public function effectiveVisibility(): string
    {
        return $this->visibility_override ?? $this->event?->visibility ?? 'public';
    }

    public function isImage(): bool
    {
        return $this->type === 'image';
    }
}
