<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared pads and tills (domain-model.md, Identity): identified by the X-Device-Id fingerprint the client sends.
 * Optional on every route until Phase 5 (EnsureEnrolledDevice behind a flag, dependency-graph.md cycle 8); enrolled
 * and revoked from People from step 0.4 so actor stamps can carry a device id from the first order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('fingerprint', 191)->unique();
            $table->foreignId('enrolled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enrolled_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
