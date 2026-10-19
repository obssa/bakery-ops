<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Response snapshots for Idempotency-Key replays (rule 7, ADR 0003). Scope is (branch_id, user_id, key).
 * user_id has no foreign key on purpose: users is owned by Identity (layer 2) and Shared stays the leaf of
 * the graph (ADR 0001); rows expire after 24 hours anyway. response_body is plain JSON (MySQL 8 / PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('key', 255);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['branch_id', 'user_id', 'key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
