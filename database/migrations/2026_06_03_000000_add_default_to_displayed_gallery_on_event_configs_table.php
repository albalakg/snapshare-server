<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE event_configs MODIFY displayed_gallery VARCHAR(255) NOT NULL DEFAULT 'EventGallerySingle'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE event_configs MODIFY displayed_gallery VARCHAR(255) NOT NULL');
    }
};
