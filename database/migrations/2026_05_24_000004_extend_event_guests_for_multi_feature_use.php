<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_guests', function (Blueprint $table) {
            $table->text('email')->nullable()->after('phone_hash');
            $table->char('email_hash', 64)->nullable()->after('email');
            $table->unsignedTinyInteger('status')->default(0)->index()->after('email_hash');
            $table->unsignedTinyInteger('party_size')->default(1)->after('status');
            $table->char('group_key', 36)->nullable()->index()->after('party_size');
            $table->json('metadata')->nullable()->after('group_key');

            $table->unique(['event_id', 'email_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('event_guests', function (Blueprint $table) {
            $table->dropUnique(['event_id', 'email_hash']);
            $table->dropColumn([
                'email',
                'email_hash',
                'status',
                'party_size',
                'group_key',
                'metadata',
            ]);
        });
    }
};
