<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kannel_alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->nullable()->constrained('kannel_endpoints')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 64);
            $table->string('severity', 16)->default('warning');
            $table->json('threshold');
            $table->unsignedInteger('cooldown_minutes')->default(15);
            $table->json('channels');
            $table->boolean('enabled')->default(true);
            $table->string('description', 1000)->nullable();
            $table->timestamps();

            $table->index(['type', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kannel_alert_rules');
    }
};
