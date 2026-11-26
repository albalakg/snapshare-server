<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (t135u): per-event add-on purchases (own table, plan orders untouched), seating entitlements,
 * and AI classifier metadata on inbound WhatsApp replies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addon_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 40)->unique();
            $table->unsignedBigInteger('event_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('product', 32);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('ILS');
            $table->string('status', 16)->default('pending'); // pending | in_progress | paid | failed | cancelled
            $table->string('provider', 16); // payplus | mock
            $table->string('page_request_uid', 80)->nullable()->unique();
            $table->text('payment_page_link')->nullable();
            $table->string('approval_number', 64)->nullable();
            $table->json('provider_payload')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });

        Schema::create('event_entitlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id')->unique();
            $table->timestamp('seating_unlocked_at')->nullable();
            $table->unsignedBigInteger('seating_purchase_id')->nullable();
            $table->unsignedInteger('seating_ai_extra_runs')->default(0);
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('classifier', 16)->nullable()->after('suggestion'); // rules | openai | mock
            $table->decimal('confidence', 4, 2)->nullable()->after('classifier');
            $table->string('suggestion_note', 255)->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn(['classifier', 'confidence', 'suggestion_note']);
        });
        Schema::dropIfExists('event_entitlements');
        Schema::dropIfExists('addon_purchases');
    }
};
