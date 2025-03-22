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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // Битрикс24 ID
            $table->string('bitrix_contact_id')->nullable();
            $table->string('bitrix_company_id')->nullable();
            
            // Основная информация
            $table->string('first_name');
            $table->string('second_name')->nullable();
            $table->string('last_name');
            $table->string('phone');
            $table->string('bitrix_phone')->nullable();
            $table->string('email')->nullable();
            $table->string('bitrix_email')->nullable();
            $table->string('telegram_chat_id')->nullable();
            
            // Информация о юридическом лице
            $table->boolean('is_legal_entity')->default(false);
            $table->string('inn')->nullable();
            $table->string('company_name')->nullable();
            $table->string('position')->nullable();
            
            // Статус и время
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();

            // Индексы для оптимизации
            $table->index(['bitrix_contact_id', 'bitrix_company_id']);
            $table->index(['phone', 'bitrix_phone']);
            $table->index(['email', 'bitrix_email']);
            $table->index(['inn']);
            $table->index(['telegram_chat_id']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
