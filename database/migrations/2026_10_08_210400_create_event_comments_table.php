<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comments attach to the whole event (umbrella tag), never to an
        // individual media item - keeps the experience from feeling like a
        // like/comment-per-photo social feed.
        Schema::create('event_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('notified')->default(false);
            $table->timestamps();

            $table->index(['event_id', 'notified']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_comments');
    }
};
