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
        Schema::table('events', function (Blueprint $table) {
            $table->json('title_i18n')->nullable()->after('title');
            $table->json('summary_i18n')->nullable()->after('summary');
            $table->json('short_summary_i18n')->nullable()->after('short_summary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['title_i18n', 'summary_i18n', 'short_summary_i18n']);
        });
    }
};
