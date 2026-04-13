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
        if (!Schema::hasColumn('orders', 'payment_page_link')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('payment_page_link', 180)->nullable()->after('token');
            });
        }

        if (Schema::hasColumn('subscriptions', 'payment_page_link')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn('payment_page_link');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('orders', 'payment_page_link')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('payment_page_link');
            });
        }
    }
};
