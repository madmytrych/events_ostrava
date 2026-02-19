<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();

            $table->string('source', 50);                 // visitostrava
            $table->string('source_url')->unique();
            $table->string('source_event_id', 50)->index(); // 179785

            $table->string('title');
            $table->dateTime('start_at')->index();
            $table->dateTime('end_at')->nullable();

            $table->string('venue')->nullable();
            $table->text('address')->nullable();
            $table->string('price_text')->nullable();

            $table->longText('description')->nullable();   // raw
            $table->text('summary')->nullable();           // AI

            $table->unsignedTinyInteger('age_min')->nullable();
            $table->unsignedTinyInteger('age_max')->nullable();
            $table->json('tags')->nullable();

            $table->boolean('kid_friendly')->nullable();
            $table->boolean('needs_review')->default(false);

            $table->string('fingerprint', 64)->unique();    // sha1 = 40, but 64 is fine
            $table->enum('status', ['new','approved','rejected'])->default('new');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
