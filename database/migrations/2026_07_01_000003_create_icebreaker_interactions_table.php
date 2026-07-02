<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icebreaker_interactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id')->index();
            $table->unsignedBigInteger('actor_profile_id');
            $table->unsignedBigInteger('target_profile_id');
            $table->unsignedTinyInteger('action');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['actor_profile_id', 'target_profile_id'], 'ib_interactions_actor_target_uq');
            $table->index(['target_profile_id', 'actor_profile_id'], 'ib_interactions_reciprocal_idx');
            $table->foreign('event_id')->references('event_id')->on('icebreaker_configs')->cascadeOnDelete();
            $table->foreign('actor_profile_id')->references('id')->on('icebreaker_profiles')->cascadeOnDelete();
            $table->foreign('target_profile_id')->references('id')->on('icebreaker_profiles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('icebreaker_interactions');
    }
};
