<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Rekognition Face Collection keys this record by ExternalImageId = people.id,
            // so we only need to remember that it has been indexed - the embeddings
            // themselves live in AWS, never in this database.
            $table->boolean('indexed_in_rekognition')->default(false);
            $table->foreignId('tag_id')->nullable()->constrained('tags')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->foreign('person_id')->references('id')->on('people')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
        });
        Schema::dropIfExists('people');
    }
};
