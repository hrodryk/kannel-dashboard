<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kannel_hourly_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained('kannel_endpoints')->cascadeOnDelete();
            $table->string('smsc_name')->nullable();
            $table->dateTime('bucket_hour');
            $table->unsignedBigInteger('mo_total_start')->default(0);
            $table->unsignedBigInteger('mo_total_end')->default(0);
            $table->unsignedBigInteger('mt_total_start')->default(0);
            $table->unsignedBigInteger('mt_total_end')->default(0);
            $table->unsignedBigInteger('mo_volume')->default(0);
            $table->unsignedBigInteger('mt_volume')->default(0);
            $table->decimal('avg_mo_rate', 12, 4)->default(0);
            $table->decimal('avg_mt_rate', 12, 4)->default(0);
            $table->decimal('peak_mo_rate', 12, 4)->default(0);
            $table->decimal('peak_mt_rate', 12, 4)->default(0);
            $table->unsignedBigInteger('avg_inbound_queue')->default(0);
            $table->unsignedBigInteger('avg_outbound_queue')->default(0);
            $table->unsignedBigInteger('peak_inbound_queue')->default(0);
            $table->unsignedBigInteger('peak_outbound_queue')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('samples')->default(0);
            $table->timestamps();

            $table->unique(['endpoint_id', 'smsc_name', 'bucket_hour']);
            $table->index(['endpoint_id', 'bucket_hour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kannel_hourly_rollups');
    }
};
