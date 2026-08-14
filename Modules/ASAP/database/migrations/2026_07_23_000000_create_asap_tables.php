<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Devices Table
        Schema::create('asap_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->string('operating_system');
            $table->string('status')->default('unknown'); // unknown, pending, verified, trusted, suspended, revoked
            $table->string('hardware_hash')->nullable();
            $table->string('hardware_version')->nullable();
            $table->string('registration_method')->default('uuid');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['uuid', 'status']);
        });

        // 2. Policies Table
        Schema::create('asap_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('warning_threshold', 5, 2)->default(40.00);
            $table->decimal('pause_threshold', 5, 2)->default(60.00);
            $table->decimal('terminate_threshold', 5, 2)->default(85.00);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // 3. Policy Rules Table
        Schema::create('asap_policy_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('asap_policies')->onDelete('cascade');
            $table->string('event_code');
            $table->decimal('weight', 5, 2);
            $table->integer('cooldown_window')->default(0); // in seconds
            $table->string('action')->default('warn'); // allow, warn, pause, terminate
            $table->timestamps();

            $table->index(['policy_id', 'event_code']);
        });

        // 4. Sessions Table
        Schema::create('asap_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained('asap_devices')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('exam_id')->constrained('exams')->onDelete('cascade');
            $table->foreignId('policy_id')->constrained('asap_policies')->onDelete('cascade');
            $table->string('status')->default('created'); // created, authenticated, environment_validation, ready, running, warning, paused, resumed, submitted, completed, archived, terminated
            $table->string('session_key_id')->nullable();
            $table->string('session_key_hash')->nullable();
            $table->text('session_key_encrypted')->nullable();
            $table->string('bootstrap_token', 64)->nullable()->unique();
            $table->timestamp('bootstrap_token_expires_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->decimal('risk_score', 5, 2)->default(0.00);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'exam_id', 'status']);
        });

        // 5. Telemetries Table
        Schema::create('asap_telemetries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('session_id')->constrained('asap_sessions')->onDelete('cascade');
            $table->string('telemetry_schema_version')->default('1.0.0');
            $table->json('payload');
            $table->timestamp('recorded_at');

            $table->index(['session_id', 'recorded_at']);
        });

        // 6. Security Events Table
        Schema::create('asap_security_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('asap_sessions')->onDelete('cascade');
            $table->string('event_code');
            $table->json('payload')->nullable();
            $table->string('severity')->default('warning'); // info, warning, critical
            $table->string('source')->default('client'); // client, server
            $table->string('category')->default('system'); // window, process, network, device, system
            $table->integer('client_sequence')->default(0);
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('policy_action')->nullable();
            $table->decimal('risk_delta', 5, 2)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['session_id', 'event_code']);
        });

        // 7. Incidents Table
        Schema::create('asap_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('asap_sessions')->onDelete('cascade');
            $table->string('status')->default('open'); // open, under_review, resolved, dismissed
            $table->decimal('risk_score_snapshot', 5, 2);
            $table->timestamps();

            $table->index(['session_id', 'status']);
        });

        // 8. Evidences Table
        Schema::create('asap_evidences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('incident_id')->constrained('asap_incidents')->onDelete('cascade');
            $table->json('telemetry_snapshot');
            $table->json('event_snapshot');
            $table->string('ip_address');
            $table->string('client_version');
            $table->string('policy_version');
            $table->string('risk_engine_version');
            $table->string('sdk_version');
            $table->string('os_version');
            $table->string('decision');
            $table->string('decision_source')->nullable();
            $table->string('engine_build')->nullable();
            $table->json('correlation_snapshot')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asap_evidences');
        Schema::dropIfExists('asap_incidents');
        Schema::dropIfExists('asap_security_events');
        Schema::dropIfExists('asap_telemetries');
        Schema::dropIfExists('asap_sessions');
        Schema::dropIfExists('asap_policy_rules');
        Schema::dropIfExists('asap_policies');
        Schema::dropIfExists('asap_devices');
    }
};
