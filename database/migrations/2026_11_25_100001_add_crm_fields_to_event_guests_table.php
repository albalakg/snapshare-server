<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guest CRM: phone becomes optional (seating-only guests such as grandparents),
 * plus notes, side and a real group FK. group_key stays for backward compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Laravel 10 without doctrine/dbal: change nullability with raw SQL (MySQL).
        DB::statement('ALTER TABLE event_guests MODIFY phone TEXT NULL');
        DB::statement('ALTER TABLE event_guests MODIFY phone_hash CHAR(64) NULL');

        Schema::table('event_guests', function (Blueprint $table) {
            if (!Schema::hasColumn('event_guests', 'notes')) {
                $table->text('notes')->nullable()->after('metadata');
            }
            if (!Schema::hasColumn('event_guests', 'side')) {
                $table->string('side', 16)->nullable()->after('party_size');
            }
            if (!Schema::hasColumn('event_guests', 'group_id')) {
                $table->unsignedBigInteger('group_id')->nullable()->index()->after('group_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('event_guests', function (Blueprint $table) {
            $table->dropColumn(['notes', 'side', 'group_id']);
        });
        // Rows without a phone would block NOT NULL; only restore when safe.
        if (DB::table('event_guests')->whereNull('phone_hash')->count() === 0) {
            DB::statement('ALTER TABLE event_guests MODIFY phone TEXT NOT NULL');
            DB::statement('ALTER TABLE event_guests MODIFY phone_hash CHAR(64) NOT NULL');
        }
    }
};
