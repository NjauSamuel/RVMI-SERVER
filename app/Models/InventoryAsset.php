<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryAsset extends Model
{
    protected $fillable = [
        'name',
        'category_id',
        'description',
        'asset_image_key',
        'purchase_date',
        'warranty_expiry',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'warranty_expiry' => 'date',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(InventoryReceipt::class)->orderBy('position')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function warrantyActive(): bool
    {
        return $this->warranty_expiry !== null && $this->warranty_expiry->isFuture();
    }
}
