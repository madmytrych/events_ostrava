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
        Schema::create('telegram_users', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id')->unique();
            $table->unsignedTinyInteger('age_min')->nullable();
            $table->unsignedTinyInteger('age_max')->nullable();
            $table->boolean('notify_enabled')->default(false);
            $table->dateTime('notify_last_sent_at')->nullable();
            $table->string('timezone', 40)->default('Europe/Prague');
            $table->string('language', 5)->nullable();
            $table->string('submission_state', 30)->nullable();
            $table->string('submission_url', 2048)->nullable();
            $table->string('submission_name', 200)->nullable();
            $table->text('submission_description')->nullable();
            $table->string('submission_contact', 200)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_users');
    }
};
