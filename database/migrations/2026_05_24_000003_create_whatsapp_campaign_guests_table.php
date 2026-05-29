<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_campaign_guests', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('guest_id');
            $table->unsignedTinyInteger('status')->default(0);
            $table->dateTime('sent_at')->nullable();
            $table->text('error_message')->nullable();

            $table->primary(['campaign_id', 'guest_id']);
            $table->foreign('campaign_id')->references('id')->on('whatsapp_campaigns')->cascadeOnDelete();
            $table->foreign('guest_id')->references('id')->on('event_guests')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_campaign_guests');
    }
};
