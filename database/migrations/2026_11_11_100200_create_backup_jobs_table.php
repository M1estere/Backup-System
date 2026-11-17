<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->json('paths');                 // что архивировать
            $table->json('excludes')->nullable();  // исключения tar
            $table->text('db_config')->nullable(); // шифруется (encrypted:array): host, port, user, password, databases[]
            $table->string('mode')->default('stream'); // stream|archive
            $table->time('run_at')->default('03:00:00');
            $table->unsignedTinyInteger('weekly_day')->default(0);   // 0=вс
            $table->unsignedTinyInteger('monthly_day')->default(1);  // число месяца
            $table->timestamps();
        });

        Schema::create('backup_job_storage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storage_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('keep_daily')->default(7);
            $table->unsignedSmallInteger('keep_weekly')->default(4);
            $table->unsignedSmallInteger('keep_monthly')->default(6);
            $table->unique(['backup_job_id', 'storage_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_job_storage');
        Schema::dropIfExists('backup_jobs');
    }
};
