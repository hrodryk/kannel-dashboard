<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kannel_smsc_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('kannel_metric_snapshots')->cascadeOnDelete();
            $table->foreignId('endpoint_id')->constrained('kannel_endpoints')->cascadeOnDelete();
            $table->string('smsc_name');
            $table->string('smsc_id')->nullable();
            $table->string('smsc_state', 32)->default('unknown');
            $table->unsignedInteger('latency_ms')->default(0);
            $table->unsignedBigInteger('mo_count')->default(0);
            $table->unsignedBigInteger('mt_count')->default(0);
            $table->unsignedBigInteger('received')->default(0);
            $table->unsignedBigInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('queued')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['endpoint_id', 'smsc_name']);
            $table->index(['snapshot_id', 'smsc_name']);
            $table->index(['smsc_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kannel_smsc_metrics');
    }
};
