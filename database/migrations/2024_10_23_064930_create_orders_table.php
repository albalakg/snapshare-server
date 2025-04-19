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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->unsigned()->index();
            $table->integer('subscription_id')->unsigned()->index();
            $table->string('order_number', 11);
            $table->integer('status')->unsigned()->index();
            $table->integer('supplier_id')->unsigned()->index()->nullable();
            $table->string('token', 100)->nullable();
            $table->decimal('price')->unsigned()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
