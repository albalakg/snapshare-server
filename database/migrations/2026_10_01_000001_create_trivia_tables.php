<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trivia_games', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->primary();
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('status')->default(0);
            $table->string('title', 80)->default('טריוויה');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('results_at')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });

        Schema::create('trivia_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->text('prompt');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('time_limit_seconds')->default(20);
            $table->unsignedInteger('max_points')->default(1000);
            $table->timestamps();

            $table->foreign('event_id')->references('event_id')->on('trivia_games')->cascadeOnDelete();
        });

        Schema::create('trivia_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('trivia_questions')->cascadeOnDelete();
            $table->string('label', 200);
            $table->boolean('is_correct')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('trivia_players', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->string('session_token', 64)->unique();
            $table->string('nickname', 40);
            $table->string('avatar_path')->nullable();
            $table->unsignedInteger('score')->default(0);
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('event_id')->on('trivia_games')->cascadeOnDelete();
            $table->index('event_id');
        });

        Schema::create('trivia_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('trivia_players')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('trivia_questions')->cascadeOnDelete();
            $table->json('selected_option_ids')->nullable();
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('points_awarded')->default(0);
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->dateTime('served_at');
            $table->dateTime('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['player_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trivia_answers');
        Schema::dropIfExists('trivia_players');
        Schema::dropIfExists('trivia_options');
        Schema::dropIfExists('trivia_questions');
        Schema::dropIfExists('trivia_games');
    }
};
