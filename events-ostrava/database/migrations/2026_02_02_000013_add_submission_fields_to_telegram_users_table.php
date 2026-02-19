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
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->string('submission_state', 30)->nullable()->after('language');
            $table->string('submission_url', 2048)->nullable()->after('submission_state');
            $table->string('submission_name', 200)->nullable()->after('submission_url');
            $table->text('submission_description')->nullable()->after('submission_name');
            $table->string('submission_contact', 200)->nullable()->after('submission_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropColumn([
                'submission_state',
                'submission_url',
                'submission_name',
                'submission_description',
                'submission_contact',
            ]);
        });
    }
};
