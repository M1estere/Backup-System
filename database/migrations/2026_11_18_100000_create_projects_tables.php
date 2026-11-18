<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_server_id')->constrained('servers')->restrictOnDelete();
            $table->string('source_path');                 // корень кода на родительском сервере
            $table->text('excludes')->nullable();          // по одному шаблону на строку (общие для всех зеркал)
            $table->boolean('enabled')->default(true);
            $table->time('sync_at')->default('03:00:00');  // ежедневно, МСК
            $table->boolean('sync_db')->default(false);    // задел на будущее: синхронизация БД
            $table->timestamps();
        });

        Schema::create('project_mirrors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->restrictOnDelete();
            $table->string('target_path');                 // куда класть код на зеркале
            $table->text('excludes')->nullable();          // доп. исключения только для этого зеркала
            $table->string('chown')->nullable();           // user:group, применяется после распаковки
            $table->boolean('enabled')->default(true);
            $table->boolean('noindex')->default(true);     // закрыть от индексации
            $table->boolean('basic_auth')->default(false);
            $table->string('basic_auth_user')->nullable();
            $table->text('basic_auth_password')->nullable(); // encrypted
            $table->text('db_config')->nullable();         // задел: реквизиты БД зеркала (encrypted:array)
            $table->string('last_status')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('project_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued'); // queued|running|success|partial|failed
            $table->string('trigger')->default('manual');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->longText('log')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_syncs');
        Schema::dropIfExists('project_mirrors');
        Schema::dropIfExists('projects');
    }
};
