<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trivia_answers', function (Blueprint $table) {
            $table->string('value_text', 200)->nullable()->after('value_number');
            $table->json('value_json')->nullable()->after('value_text');
        });

        // teams_mode "tables" stores a free-form table name/number (max 40 chars).
        // The MVP column was VARCHAR(32).
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE trivia_players MODIFY team VARCHAR(40) NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('UPDATE trivia_players SET team = LEFT(team, 32) WHERE team IS NOT NULL');
            DB::statement('ALTER TABLE trivia_players MODIFY team VARCHAR(32) NULL');
        }

        Schema::table('trivia_answers', function (Blueprint $table) {
            $table->dropColumn(['value_text', 'value_json']);
        });
    }
};
