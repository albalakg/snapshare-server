<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * WhatsApp guest messaging (PLAN.md §7). New tables only + nullable columns on event_guests.
 * Legacy whatsapp_campaigns / whatsapp_campaign_guests are not modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_whatsapp_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->primary();
            $table->boolean('quiet_hours_enabled')->default(true);
            $table->boolean('shabbat_guard_enabled')->default(true);
            $table->boolean('sms_fallback_enabled')->default(false);
            $table->boolean('pro_addon')->default(false);          // TODO(pricing): set by checkout
            $table->unsignedInteger('topups')->default(0);         // TODO(pricing): set by checkout
            $table->unsignedInteger('test_sends_used')->default(0);
            $table->json('variables')->nullable();                 // host overrides: couple_names, venue_name, ...
            $table->json('rsvp_config')->nullable();               // RSVP bot / web questionnaire
            $table->timestamps();
        });

        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id')->nullable()->index(); // null = system catalog copy
            $table->string('key', 64);
            $table->string('base_key', 64)->nullable();
            $table->string('title', 160);
            $table->string('stage', 16)->default('manual');
            $table->string('category', 16)->default('marketing'); // marketing | utility
            $table->string('language', 8)->default('he');
            $table->string('header', 60)->nullable();
            $table->text('body');
            $table->string('footer', 60)->nullable();
            $table->json('buttons')->nullable();
            $table->string('meta_name', 120)->nullable();
            $table->string('meta_template_id', 64)->nullable();
            $table->string('meta_status', 16)->default('draft'); // draft | pending | approved | rejected
            $table->string('meta_reason', 500)->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'key']);
        });

        Schema::create('whatsapp_automations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id')->index();
            $table->string('key', 64);
            $table->string('template_key', 64);
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('title', 160);
            $table->boolean('enabled')->default(false);
            $table->integer('offset_days')->default(0);
            $table->string('send_time', 5)->default('10:00');
            $table->json('segment')->nullable();
            $table->unsignedBigInteger('send_id')->nullable(); // materialized occurrence in the queue
            $table->timestamps();
            $table->unique(['event_id', 'key']);
        });

        Schema::create('whatsapp_sends', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('source', 16)->default('manual'); // manual | automation
            $table->unsignedBigInteger('automation_id')->nullable()->index();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('template_key', 64)->nullable();
            $table->string('title', 160);
            $table->string('category', 16)->default('marketing');
            $table->string('header', 60)->nullable();
            $table->text('body');
            $table->string('footer', 60)->nullable();
            $table->json('buttons')->nullable();
            $table->json('segment')->nullable();
            $table->string('audience_mode', 8)->default('fixed'); // fixed | dynamic (automation re-evaluates)
            $table->string('status', 16)->default('scheduled')->index();
            // scheduled | paused | sending | sent | partial | cancelled | review (waiting for Meta approval)
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->boolean('override_quiet')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('content_version')->default(1);
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_send_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('send_id')->index();
            $table->unsignedBigInteger('event_id')->index();
            $table->unsignedBigInteger('guest_id')->index();
            $table->string('status', 16)->default('pending')->index();
            // pending | sending | sent | delivered | read | failed | cancelled | skipped
            $table->string('channel', 8)->default('whatsapp'); // whatsapp | sms
            $table->unsignedInteger('claimed_version')->nullable();
            $table->unsignedInteger('content_version_sent')->nullable();
            $table->boolean('locked')->default(false);
            $table->boolean('credited')->default(false); // counted against the per-guest quota
            $table->string('provider_message_id', 128)->nullable()->index();
            $table->string('skip_reason', 32)->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();
            $table->unique(['send_id', 'guest_id']);
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id')->index();
            $table->unsignedBigInteger('guest_id')->nullable()->index();
            $table->unsignedBigInteger('send_id')->nullable();
            $table->string('direction', 3); // in | out
            $table->string('kind', 16)->default('text'); // text | button | template | interactive | sms | system
            $table->text('body')->nullable(); // encrypted cast
            $table->json('payload')->nullable();
            $table->string('provider_message_id', 128)->nullable()->unique();
            $table->string('context_id', 128)->nullable();
            $table->string('status', 16)->nullable();
            $table->string('intent', 24)->nullable(); // rsvp_update | question | opt_out | other
            $table->json('suggestion')->nullable();
            $table->string('handled', 16)->nullable(); // bot | host | applied | dismissed
            $table->timestamp('read_by_host_at')->nullable();
            $table->timestamps();
            $table->index(['event_id', 'guest_id', 'id']);
        });

        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('guest_id');
            $table->string('step', 24)->nullable(); // RSVP bot state
            $table->json('data')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'guest_id']);
        });

        Schema::create('whatsapp_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id')->index();
            $table->unsignedBigInteger('send_id')->nullable()->index();
            $table->unsignedBigInteger('guest_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 32);
            $table->json('changes')->nullable();
            $table->unsignedInteger('affected_pending')->default(0);
            $table->unsignedInteger('locked_unchanged')->default(0);
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('event_guests', function (Blueprint $table) {
            if (!Schema::hasColumn('event_guests', 'wa_opted_out_at')) {
                $table->timestamp('wa_opted_out_at')->nullable();
            }
            if (!Schema::hasColumn('event_guests', 'rsvp_responded_at')) {
                $table->timestamp('rsvp_responded_at')->nullable();
            }
            if (!Schema::hasColumn('event_guests', 'rsvp_source')) {
                $table->string('rsvp_source', 16)->nullable(); // whatsapp | web | sms | host
            }
        });
    }

    public function down(): void
    {
        Schema::table('event_guests', function (Blueprint $table) {
            foreach (['wa_opted_out_at', 'rsvp_responded_at', 'rsvp_source'] as $col) {
                if (Schema::hasColumn('event_guests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::dropIfExists('whatsapp_audit_logs');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_send_recipients');
        Schema::dropIfExists('whatsapp_sends');
        Schema::dropIfExists('whatsapp_automations');
        Schema::dropIfExists('whatsapp_templates');
        Schema::dropIfExists('event_whatsapp_settings');
    }
};
