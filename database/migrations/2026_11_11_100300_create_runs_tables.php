<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_job_id')->constrained()->cascadeOnDelete();
            $table->string('tier')->default('daily');        // daily|weekly|monthly
            $table->string('status')->default('queued');     // queued|running|success|partial|failed
            $table->string('trigger')->default('schedule');  // schedule|manual
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['backup_job_id', 'status']);
        });

        Schema::create('run_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storage_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending|running|success|failed
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->json('objects')->nullable(); // [{key,size,sha256}]
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'storage_id']);
        });

        Schema::create('run_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->string('level')->default('info'); // info|warning|error
            $table->string('step')->nullable();       // ssh|dump|tar|upload|prune
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();
            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_logs');
        Schema::dropIfExists('run_targets');
        Schema::dropIfExists('runs');
    }
};
