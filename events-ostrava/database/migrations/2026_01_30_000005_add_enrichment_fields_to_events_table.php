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
        Schema::table('events', function (Blueprint $table) {
            $table->longText('description_raw')->nullable()->after('description');
            $table->string('location_name')->nullable()->after('venue');

            $table->text('short_summary')->nullable()->after('summary');
            $table->string('indoor_outdoor', 20)->nullable()->after('short_summary');
            $table->string('category', 50)->nullable()->after('indoor_outdoor');
            $table->string('language', 10)->nullable()->after('category');

            $table->dateTime('enriched_at')->nullable()->after('language');
            $table->unsignedTinyInteger('enrichment_attempts')->default(0)->after('enriched_at');
            $table->unsignedBigInteger('enrichment_log_id')->nullable()->after('enrichment_attempts');

            $table->foreignId('duplicate_of_event_id')->nullable()->after('fingerprint')->index();
            $table->boolean('is_active')->default(true)->after('status')->index();
        });

        DB::table('events')
            ->whereNull('description_raw')
            ->update(['description_raw' => DB::raw('description')]);

        DB::table('events')
            ->whereNull('location_name')
            ->update(['location_name' => DB::raw('venue')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['duplicate_of_event_id']);
            $table->dropIndex(['is_active']);
            $table->dropColumn([
                'description_raw',
                'location_name',
                'short_summary',
                'indoor_outdoor',
                'category',
                'language',
                'enriched_at',
                'enrichment_attempts',
                'enrichment_log_id',
                'duplicate_of_event_id',
                'is_active',
            ]);
        });
    }
};
