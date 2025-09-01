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
        if(Schema::hasColumn('event_assets', 'is_displayed')) {
            return;
        }

        Schema::table('event_assets', function (Blueprint $table) {
            $table->boolean('is_displayed')->default(true)->after('asset_type')->comment('Indicates if the asset is displayed in the event gallery');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if(!Schema::hasColumn('event_assets', 'is_displayed')) {
            return;
        }
        
        Schema::table('event_assets', function (Blueprint $table) {
            $table->dropColumn('is_displayed');
        });
    }
};
