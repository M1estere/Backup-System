<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->text('emails')->nullable();               // получатели, по одному на строку
            $table->boolean('on_failure')->default(true);     // письмо при ошибке / частичном успехе
            $table->boolean('on_success')->default(false);    // письмо при каждом успешном запуске
            $table->boolean('digest_enabled')->default(true); // ежедневная сводка
            $table->time('digest_time')->default('08:00:00');
            $table->date('last_digest_on')->nullable();
            $table->string('smtp_host')->nullable();
            $table->unsignedInteger('smtp_port')->default(587);
            $table->string('smtp_security')->default('tls');  // tls (STARTTLS) | ssl | none
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();        // encrypted
            $table->string('from_address')->nullable();
            $table->string('from_name')->default('Backup Panel');
            $table->timestamps();
        });
        Schema::table('runs', fn (Blueprint $t) => $t->timestamp('notified_at')->nullable());
        Schema::table('project_syncs', fn (Blueprint $t) => $t->timestamp('notified_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('project_syncs', fn (Blueprint $t) => $t->dropColumn('notified_at'));
        Schema::table('runs', fn (Blueprint $t) => $t->dropColumn('notified_at'));
        Schema::dropIfExists('notification_settings');
    }
};
