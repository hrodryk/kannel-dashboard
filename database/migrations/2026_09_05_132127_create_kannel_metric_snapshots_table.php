<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kannel_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained('kannel_endpoints')->cascadeOnDelete();
            $table->timestamp('polled_at')->index();
            $table->boolean('success')->default(true);
            $table->unsignedInteger('raw_size_bytes')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedTinyInteger('retries')->default(0);
            $table->unsignedBigInteger('uptime_seconds')->default(0);
            $table->unsignedBigInteger('mo_total')->default(0);
            $table->unsignedBigInteger('mt_total')->default(0);
            $table->decimal('mo_rate', 12, 4)->default(0);
            $table->decimal('mt_rate', 12, 4)->default(0);
            $table->unsignedBigInteger('inbound_queue')->default(0);
            $table->unsignedBigInteger('outbound_queue')->default(0);
            $table->unsignedBigInteger('dlr_queue')->default(0);
            $table->unsignedBigInteger('received')->default(0);
            $table->unsignedBigInteger('sent')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('errors')->nullable();
            $table->string('box_id', 255)->nullable();
            $table->timestamps();

            $table->index(['endpoint_id', 'polled_at']);
            $table->index(['success', 'polled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kannel_metric_snapshots');
    }
};
