<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('admin')->after('password');
        });

        // Первый (текущий) пользователь становится суперпользователем.
        $first = DB::table('users')->orderBy('id')->value('id');
        if ($first) {
            DB::table('users')->where('id', $first)->update(['role' => 'superuser']);
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
