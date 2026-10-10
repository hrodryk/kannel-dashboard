<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kannel_alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('kannel_alert_rules')->cascadeOnDelete();
            $table->foreignId('endpoint_id')->nullable()->constrained('kannel_endpoints')->cascadeOnDelete();
            $table->string('smsc_name')->nullable();
            $table->string('fingerprint', 255)->unique();
            $table->string('state', 32)->default('firing');
            $table->string('severity', 16)->default('warning');
            $table->string('title');
            $table->text('message');
            $table->json('payload')->nullable();
            $table->timestamp('fired_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->unsignedInteger('notify_attempts')->default(0);
            $table->timestamps();

            $table->index(['state', 'severity']);
            $table->index(['rule_id', 'fired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kannel_alert_events');
    }
};
