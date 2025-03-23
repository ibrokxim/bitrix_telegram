<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class FixUsersTableCharset extends Migration
{
    public function up()
    {
        // Изменяем кодировку таблицы
        DB::statement('ALTER TABLE users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        
        // Обновляем существующие данные
        DB::table('users')->get()->each(function ($user) {
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'first_name' => $this->fixEncoding($user->first_name),
                    'last_name' => $this->fixEncoding($user->last_name),
                    'second_name' => $this->fixEncoding($user->second_name),
                    'company_name' => $this->fixEncoding($user->company_name),
                ]);
        });
    }

    public function down()
    {
        // Возвращаем предыдущую кодировку если нужно
        DB::statement('ALTER TABLE users CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci');
    }

    private function fixEncoding($string)
    {
        if (empty($string)) return $string;
        
        // Пробуем различные варианты кодировок
        $encodings = ['UTF-8', 'Windows-1251', 'KOI8-R', 'ISO-8859-5'];
        
        foreach ($encodings as $encoding) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $string);
            if ($converted !== false) {
                return $converted;
            }
        }
        
        return $string;
    }
} 