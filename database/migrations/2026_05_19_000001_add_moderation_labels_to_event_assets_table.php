<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('event_assets', 'moderation_labels')) {
            return;
        }

        Schema::table('event_assets', function (Blueprint $table) {
            $table->json('moderation_labels')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('event_assets', 'moderation_labels')) {
            return;
        }

        Schema::table('event_assets', function (Blueprint $table) {
            $table->dropColumn('moderation_labels');
        });
    }
};
