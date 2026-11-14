<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_configs', function (Blueprint $table) {
            if (!Schema::hasColumn('event_configs', 'welcome_text')) {
                $table->string('welcome_text', 255)->nullable()->after('qr_card_text');
            }
            if (!Schema::hasColumn('event_configs', 'image_display_time')) {
                $table->unsignedInteger('image_display_time')->nullable()->after('welcome_text');
            }
            if (!Schema::hasColumn('event_configs', 'video_display_time')) {
                $table->unsignedInteger('video_display_time')->nullable()->after('image_display_time');
            }
            if (!Schema::hasColumn('event_configs', 'manual_photo_approval')) {
                $table->boolean('manual_photo_approval')->default(false)->after('video_display_time');
            }
        });
    }

    public function down(): void
    {
        // Add-only policy: do not drop columns on rollback.
    }
};
