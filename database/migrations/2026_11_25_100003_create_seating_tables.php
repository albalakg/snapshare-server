<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seating planner storage. Host-only operational data, intentionally NOT in hub_config.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seating_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id')->unique();
            $table->json('canvas')->nullable(); // { fixtures: [...] }
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });

        Schema::create('seating_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_id')->index();
            $table->string('label', 60);
            $table->string('shape', 8)->default('round');
            $table->unsignedSmallInteger('seats')->default(10);
            $table->string('side', 16)->nullable();
            $table->integer('x')->default(0);
            $table->integer('y')->default(0);
            $table->unsignedSmallInteger('w')->default(160);
            $table->unsignedSmallInteger('h')->default(160);
            $table->smallInteger('rot')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('plan_id')->references('id')->on('seating_plans')->cascadeOnDelete();
        });

        Schema::create('seating_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_id')->index();
            $table->unsignedBigInteger('table_id')->index();
            $table->unsignedBigInteger('guest_id');
            $table->unsignedSmallInteger('seat')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'guest_id']);
            $table->foreign('plan_id')->references('id')->on('seating_plans')->cascadeOnDelete();
            $table->foreign('table_id')->references('id')->on('seating_tables')->cascadeOnDelete();
            $table->foreign('guest_id')->references('id')->on('event_guests')->cascadeOnDelete();
        });

        Schema::create('seating_constraints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id')->index();
            $table->string('type', 16); // together | apart
            $table->json('guest_ids');
            $table->string('note', 200)->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });

        Schema::create('ai_seating_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('engine', 12); // ai | packer
            $table->string('provider', 24)->nullable(); // openai | mock | packer
            $table->string('model', 64)->nullable();
            $table->string('prompt_version', 16)->nullable();
            $table->string('status', 12)->index(); // pending | succeeded | failed | applied | discarded
            $table->unsignedInteger('base_revision')->default(0);
            $table->json('options')->nullable();
            $table->json('proposal')->nullable();
            $table->json('unplaced')->nullable();
            $table->json('warnings')->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_seating_runs');
        Schema::dropIfExists('seating_constraints');
        Schema::dropIfExists('seating_assignments');
        Schema::dropIfExists('seating_tables');
        Schema::dropIfExists('seating_plans');
    }
};
