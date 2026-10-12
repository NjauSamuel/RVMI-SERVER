<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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
        'taken_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'taken_at' => 'datetime',
            'processing_attempts' => 'integer',
        ];
    }

    /**
     * A chosen calendar day, stored at noon UTC so the day does not slip
     * when the phone converts it to local time.
     */
    public static function takenAtFromInput(string $value): Carbon
    {
        $day = substr($value, 0, 10);

        $parsed = Carbon::createFromFormat('!Y-m-d', $day, 'UTC');

        if ($parsed === false) {
            throw new \InvalidArgumentException('Invalid photo date.');
        }

        return $parsed->setTime(12, 0);
    }

    public function displayAt(): Carbon
    {
        return $this->taken_at ?? $this->created_at ?? now();
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

    public function favoritedBy(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserMediaFavorite::class);
    }

    public function effectiveVisibility(): string
    {
        if ($this->visibility_override) {
            return $this->visibility_override;
        }

        // Include a soft-deleted event so its photos keep that visibility
        // until the event is restored.
        return $this->event()->withTrashed()->value('visibility') ?? 'public';
    }

    public function isImage(): bool
    {
        return $this->type === 'image';
    }
}
