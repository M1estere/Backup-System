<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_mirrors', function (Blueprint $table) {
            $table->string('docroot')->nullable()->after('target_path'); // подпапка веб-корня (для robots.txt), напр. www
        });
    }

    public function down(): void
    {
        Schema::table('project_mirrors', fn (Blueprint $t) => $t->dropColumn('docroot'));
    }
};
