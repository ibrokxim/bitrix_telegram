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
        if (!Schema::hasColumn('orders', 'bitrix_deal_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('bitrix_deal_id')->nullable()->after('user_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('orders', 'bitrix_deal_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('bitrix_deal_id');
            });
        }
    }
};
