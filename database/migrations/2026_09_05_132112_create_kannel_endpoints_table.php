<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kannel_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url', 500);
            $table->string('admin_user')->nullable();
            $table->text('admin_password')->nullable();
            $table->string('admin_api_key_header')->nullable();
            $table->string('admin_api_key_value')->nullable();
            $table->unsignedSmallInteger('poll_interval_seconds')->default(30);
            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->unsignedTinyInteger('consecutive_failures')->default(0);
            $table->string('status', 32)->default('healthy');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kannel_endpoints');
    }
};
