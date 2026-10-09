<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->enum('type', ['image', 'video'])->default('image');

            // Storage keys. s3_key is the "working" location: staging/{uuid} while
            // unapproved, media/{event_id}/{uuid} once approved and copied.
            $table->string('s3_key');
            $table->string('thumbnail_key')->nullable();
            $table->string('blurhash')->nullable();

            $table->enum('status', ['staging', 'pending_processing', 'pending_review', 'approved', 'rejected'])
                ->default('staging');

            // null = inherit the parent event's visibility.
            $table->enum('visibility_override', ['public', 'private'])->nullable();

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'type']);
            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_items');
    }
};
