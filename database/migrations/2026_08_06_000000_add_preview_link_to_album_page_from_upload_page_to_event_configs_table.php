<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_configs', function (Blueprint $table) {
            $table->boolean('preview_link_to_album_page_from_upload_page')
                ->default(true)
                ->after('qr_card_text');
        });
    }

    public function down(): void
    {
        Schema::table('event_configs', function (Blueprint $table) {
            $table->dropColumn('preview_link_to_album_page_from_upload_page');
        });
    }
};
