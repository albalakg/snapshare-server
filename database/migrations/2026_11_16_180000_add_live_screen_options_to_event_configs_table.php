<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local-only live screen options JSON for the SnapShare live screen upgrade.
 * Never deploy this migration to production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_configs', function (Blueprint $table) {
            if (!Schema::hasColumn('event_configs', 'live_screen_options')) {
                $table->json('live_screen_options')->nullable()->after('video_display_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('event_configs', function (Blueprint $table) {
            if (Schema::hasColumn('event_configs', 'live_screen_options')) {
                $table->dropColumn('live_screen_options');
            }
        });
    }
};
