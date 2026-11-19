<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('db_host')->default('localhost');
            $table->unsignedInteger('db_port')->default(3306);
            $table->string('db_user')->nullable();
            $table->text('db_password')->nullable();   // encrypted
            $table->text('db_names')->nullable();      // по одной базе на строку
        });
        Schema::table('project_mirrors', function (Blueprint $table) {
            $table->string('db_host')->default('localhost');
            $table->unsignedInteger('db_port')->default(3306);
            $table->string('db_user')->nullable();
            $table->text('db_password')->nullable();   // encrypted
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn(['db_host', 'db_port', 'db_user', 'db_password', 'db_names']));
        Schema::table('project_mirrors', fn (Blueprint $t) => $t->dropColumn(['db_host', 'db_port', 'db_user', 'db_password']));
    }
};
