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
        Schema::table('event_configs', function (Blueprint $table) {
                $table->boolean('preview_guests_assets_in_gallery')->default(true);
                $table->boolean('preview_owners_assets_in_gallery')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_configs', function (Blueprint $table) {
            $table->dropColumn('preview_guests_assets_in_gallery');
            $table->dropColumn('preview_owners_assets_in_gallery');
        });
    }
};
