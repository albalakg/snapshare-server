<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_configs', function (Blueprint $table) {
            $table->boolean('video_upload_enabled')->default(true)->after('displayed_gallery');
        });
    }

    public function down(): void
    {
        Schema::table('event_configs', function (Blueprint $table) {
            $table->dropColumn('video_upload_enabled');
        });
    }
};
