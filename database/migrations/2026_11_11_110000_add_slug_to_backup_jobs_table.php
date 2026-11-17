<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('name'); // имя папки в хранилищах, не меняется при переименовании
        });

        $used = [];
        foreach (DB::table('backup_jobs')->orderBy('id')->get() as $job) {
            $base = Str::slug($job->name, '_') ?: 'job';
            $slug = $base;
            for ($i = 2; in_array($slug, $used, true); $i++) {
                $slug = $base.'_'.$i;
            }
            $used[] = $slug;
            DB::table('backup_jobs')->where('id', $job->id)->update(['slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
