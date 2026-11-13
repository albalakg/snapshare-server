<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trivia_games', function (Blueprint $table) {
            $table->string('theme', 32)->default('elegant')->after('title');
            $table->string('pace', 16)->default('host')->after('theme');
            $table->string('teams_mode', 16)->default('none')->after('pace');
            $table->json('team_labels')->nullable()->after('teams_mode');
            $table->boolean('speed_bonus')->default(true)->after('team_labels');
            $table->string('prize_text', 160)->nullable()->after('speed_bonus');
            $table->unsignedSmallInteger('reveal_seconds')->default(6)->after('prize_text');
            $table->unsignedSmallInteger('default_time')->default(20)->after('reveal_seconds');
            $table->unsignedInteger('default_points')->default(1000)->after('default_time');
            $table->unsignedBigInteger('current_question_id')->nullable()->after('default_points');
            $table->string('current_phase', 32)->nullable()->after('current_question_id');
            $table->dateTime('question_started_at')->nullable()->after('current_phase');

            $table->foreign('current_question_id')
                ->references('id')
                ->on('trivia_questions')
                ->nullOnDelete();
        });

        Schema::table('trivia_questions', function (Blueprint $table) {
            $table->string('type', 32)->default('choice')->after('prompt');
            $table->string('fun_fact', 280)->nullable()->after('type');
            $table->unsignedBigInteger('media_asset_id')->nullable()->after('fun_fact');
            $table->json('config')->nullable()->after('media_asset_id');

            $table->foreign('media_asset_id')
                ->references('id')
                ->on('event_assets')
                ->nullOnDelete();
        });

        Schema::table('trivia_players', function (Blueprint $table) {
            $table->string('team', 32)->nullable()->after('avatar_path');
        });

        Schema::table('trivia_answers', function (Blueprint $table) {
            $table->decimal('value_number', 12, 4)->nullable()->after('selected_option_ids');
        });
    }

    public function down(): void
    {
        Schema::table('trivia_games', function (Blueprint $table) {
            $table->dropForeign(['current_question_id']);
            $table->dropColumn([
                'theme',
                'pace',
                'teams_mode',
                'team_labels',
                'speed_bonus',
                'prize_text',
                'reveal_seconds',
                'default_time',
                'default_points',
                'current_question_id',
                'current_phase',
                'question_started_at',
            ]);
        });

        Schema::table('trivia_questions', function (Blueprint $table) {
            $table->dropForeign(['media_asset_id']);
            $table->dropColumn(['type', 'fun_fact', 'media_asset_id', 'config']);
        });

        Schema::table('trivia_players', function (Blueprint $table) {
            $table->dropColumn('team');
        });

        Schema::table('trivia_answers', function (Blueprint $table) {
            $table->dropColumn('value_number');
        });
    }
};
