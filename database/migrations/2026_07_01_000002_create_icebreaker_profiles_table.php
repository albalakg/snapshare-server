<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icebreaker_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id')->index();
            $table->string('user_session_token', 255)->index();
            $table->string('display_name', 50);
            $table->text('avatar_url');
            $table->string('gender', 20)->nullable();
            $table->json('target_genders')->nullable();
            $table->unsignedTinyInteger('primary_intent');
            $table->string('bio', 150)->nullable();
            $table->text('instagram_handle')->nullable();
            $table->text('whatsapp_number')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['event_id', 'user_session_token'], 'ib_profiles_event_session_uq');
            $table->index(['event_id', 'primary_intent', 'is_active'], 'ib_profiles_discovery_idx');
            $table->foreign('event_id')->references('event_id')->on('icebreaker_configs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('icebreaker_profiles');
    }
};
