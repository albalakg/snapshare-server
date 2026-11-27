<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email delivery log (v2 emails): idempotency (dedupe_key), 1/day cap for digest emails,
 * and deferred sends held by quiet hours / Shabbat (payload = serialized Mailable, sent by emails:send-deferred).
 * New table only — no existing table is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mail_deliveries')) {
            return;
        }
        Schema::create('mail_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('mail_key', 64);
            $table->string('mailable', 191);
            $table->string('recipient', 191);
            $table->string('category', 24);
            $table->string('dedupe_key', 191)->nullable();
            $table->unsignedBigInteger('event_id')->nullable();
            // sent | deferred | skipped_cap | skipped_dedupe | failed | inactive
            $table->string('status', 24);
            $table->timestamp('send_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->longText('payload')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamps();

            $table->index(['recipient', 'category', 'created_at']);
            $table->index(['status', 'send_at']);
            $table->index('dedupe_key');
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_deliveries');
    }
};
