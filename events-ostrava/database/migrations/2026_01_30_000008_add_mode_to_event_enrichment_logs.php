<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('event_enrichment_logs', function (Blueprint $table) {
            $table->string('mode', 20)->default('ai')->after('event_id');
        });

        DB::table('event_enrichment_logs')
            ->whereNull('mode')
            ->update(['mode' => 'ai']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_enrichment_logs', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
