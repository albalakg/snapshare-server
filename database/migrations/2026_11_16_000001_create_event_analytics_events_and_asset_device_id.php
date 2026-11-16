<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('event_analytics_events')) {
            Schema::create('event_analytics_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('event_id')->index();
                $table->char('device_id', 36);
                $table->string('name', 64)->index();
                $table->json('meta')->nullable();
                $table->string('ip', 50)->nullable();
                $table->string('user_agent')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['event_id', 'name', 'created_at']);
                $table->index(['event_id', 'device_id', 'name']);
                $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            });
        }

        if (!Schema::hasColumn('event_assets', 'device_id')) {
            Schema::table('event_assets', function (Blueprint $table) {
                $table->char('device_id', 36)->nullable()->after('created_by_guest')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('event_assets', 'device_id')) {
            Schema::table('event_assets', function (Blueprint $table) {
                $table->dropColumn('device_id');
            });
        }

        Schema::dropIfExists('event_analytics_events');
    }
};
