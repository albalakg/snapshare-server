<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trivia: moderation (kick / rejoin block / hide / approve queue),
 * live-screen sound settings and live reactions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trivia_games', function (Blueprint $table) {
            $table->boolean('sound_enabled')->default(true)->after('default_points');
            $table->unsignedTinyInteger('sound_volume')->default(70)->after('sound_enabled');
            $table->boolean('reactions_enabled')->default(true)->after('sound_volume');
        });

        Schema::table('trivia_questions', function (Blueprint $table) {
            $table->boolean('requires_approval')->default(false)->after('config');
        });

        Schema::table('trivia_players', function (Blueprint $table) {
            $table->string('device_id', 64)->nullable()->after('session_token');
            $table->dateTime('kicked_at')->nullable()->after('finished_at');
            $table->boolean('rejoin_blocked')->default(false)->after('kicked_at');
            $table->index(['event_id', 'device_id']);
        });

        Schema::table('trivia_answers', function (Blueprint $table) {
            $table->boolean('is_flagged')->default(false)->after('value_json');
            $table->dateTime('hidden_at')->nullable()->after('is_flagged');
            $table->dateTime('approved_at')->nullable()->after('hidden_at');
        });

        Schema::create('trivia_reactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('player_id')->nullable();
            $table->string('kind', 16);
            $table->dateTime('created_at')->nullable();

            $table->index(['event_id', 'id']);
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('player_id')->references('id')->on('trivia_players')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trivia_reactions');

        Schema::table('trivia_answers', function (Blueprint $table) {
            $table->dropColumn(['is_flagged', 'hidden_at', 'approved_at']);
        });

        Schema::table('trivia_players', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'device_id']);
            $table->dropColumn(['device_id', 'kicked_at', 'rejoin_blocked']);
        });

        Schema::table('trivia_questions', function (Blueprint $table) {
            $table->dropColumn('requires_approval');
        });

        Schema::table('trivia_games', function (Blueprint $table) {
            $table->dropColumn(['sound_enabled', 'sound_volume', 'reactions_enabled']);
        });
    }
};
