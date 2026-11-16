<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->unsignedTinyInteger('percent');
            $table->unsignedTinyInteger('status')->index();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('discount_code_id')->nullable()->index()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('discount_code_id');
        });

        Schema::dropIfExists('discount_codes');
    }
};
