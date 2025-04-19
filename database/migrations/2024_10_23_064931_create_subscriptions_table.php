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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->integer('status')->unsigned()->index();
            $table->decimal('price')->unsigned()->index();
            $table->integer('events_allowed')->unsigned()->index();
            $table->integer('files_allowed')->unsigned()->index();
            $table->integer('storage_time')->unsigned()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
