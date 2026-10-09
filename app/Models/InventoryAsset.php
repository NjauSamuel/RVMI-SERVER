<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAsset extends Model
{
    protected $fillable = [
        'name',
        'category_id',
        'description',
        'asset_image_key',
        'receipt_file_key',
        'receipt_mime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function warrantyActive(): bool
    {
        return $this->warranty_expiry !== null && $this->warranty_expiry->isFuture();
    }
}
