<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->boolean('notify_new_events')->default(false)->after('notify_last_sent_at');
            $table->dateTime('notify_new_events_last_sent_at')->nullable()->after('notify_new_events');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropColumn(['notify_new_events', 'notify_new_events_last_sent_at']);
        });
    }
};
