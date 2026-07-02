<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icebreaker_configs', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->primary();
            $table->unsignedTinyInteger('status')->index()->default(0);
            $table->json('allowed_intents');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->dateTime('purged_at')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('icebreaker_configs');
    }
};
