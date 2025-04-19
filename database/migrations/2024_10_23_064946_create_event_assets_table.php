<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('event_assets', function (Blueprint $table) {
            $table->id();
            $table->integer('event_id')->unsigned()->index();
            $table->integer('asset_type')->unsigned()->index();
            $table->string('path');
            $table->integer('status')->unsigned()->index();
            $table->string('user_agent');
            $table->string('ip', 50);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_assets');
    }
};
