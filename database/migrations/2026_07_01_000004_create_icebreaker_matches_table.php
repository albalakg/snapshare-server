<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icebreaker_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id')->index();
            $table->unsignedBigInteger('profile_low_id');
            $table->unsignedBigInteger('profile_high_id');
            $table->timestamps();

            $table->unique(['profile_low_id', 'profile_high_id'], 'ib_matches_pair_uq');
            $table->foreign('event_id')->references('event_id')->on('icebreaker_configs')->cascadeOnDelete();
            $table->foreign('profile_low_id')->references('id')->on('icebreaker_profiles')->cascadeOnDelete();
            $table->foreign('profile_high_id')->references('id')->on('icebreaker_profiles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('icebreaker_matches');
    }
};
