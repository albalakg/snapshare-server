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
        Schema::create('event_assets_downloads', function (Blueprint $table) {
            $table->id();
            $table->integer('event_id')->unsigned()->index();
            $table->text('event_assets');
            $table->integer('status')->unsigned()->index();
            $table->string('path')->nullable();
            $table->integer('created_by')->unsigned()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_assets_downloads');
    }
};
