<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_configs', function (Blueprint $table) {
            $table->string('qr_card_design')->nullable()->after('video_upload_enabled');
            $table->text('qr_card_text')->nullable()->after('qr_card_design');
        });
    }

    public function down(): void
    {
        Schema::table('event_configs', function (Blueprint $table) {
            $table->dropColumn(['qr_card_design', 'qr_card_text']);
        });
    }
};
