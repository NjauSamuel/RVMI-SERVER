<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('kind', ['person', 'moment', 'category'])->default('moment');
            // Set when kind = 'person' and the tag has a trained face in the
            // Rekognition collection, linking back to people.id.
            $table->foreignId('person_id')->nullable();
            $table->timestamps();

            $table->unique(['name', 'kind']);
        });

        Schema::create('media_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->constrained('media_items')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->enum('source', ['manual', 'auto'])->default('manual');
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->timestamps();

            $table->unique(['media_item_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_tag');
        Schema::dropIfExists('tags');
    }
};
