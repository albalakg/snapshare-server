<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'event_type')) {
                $table->string('event_type', 32)->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        // Add-only policy: do not drop columns on rollback.
    }
};
