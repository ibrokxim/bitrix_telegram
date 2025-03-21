<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('bitrix_contact_id')->nullable()->after('id');
            $table->string('bitrix_company_id')->nullable()->after('bitrix_contact_id');
            $table->string('bitrix_phone')->nullable()->after('phone');
            $table->string('bitrix_email')->nullable()->after('email');
            $table->timestamp('last_sync_at')->nullable();
            $table->index(['bitrix_contact_id', 'bitrix_company_id', 'phone', 'inn']);
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'bitrix_contact_id',
                'bitrix_company_id',
                'bitrix_phone',
                'bitrix_email',
                'last_sync_at'
            ]);
        });
    }
}; 