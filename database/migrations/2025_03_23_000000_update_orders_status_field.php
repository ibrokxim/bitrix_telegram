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
        Schema::table('orders', function (Blueprint $table) {
            // Сначала удаляем старое поле status
            $table->dropColumn('status');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Создаем новое поле status с enum
            $table->enum('status', [
                'new',          // Новый заказ
                'processed',    // Заказ обработан
                'confirmed',    // Заказ подтвержден
                'shipped',      // Заказ отправлен
                'delivered',    // Заказ доставлен
                'completed',    // Заказ завершен
                'canceled',     // Заказ отменен
                'rejected'      // Заказ отклонен
            ])->default('new');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('status');
            $table->string('status')->default('pending');
        });
    }
}; 