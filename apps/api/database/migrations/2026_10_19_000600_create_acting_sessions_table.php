<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Acting as" on the shared waiter pad (domain-model.md, Identity): a short code verified on a device opens a
 * 10-minute session; every write in that window stamps acting_user_id = the session's user (rule 4). Sessions end
 * by the clock (expires_at) or explicitly (ended_at); they are never deleted, so no soft delete column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acting_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acting_sessions');
    }
};
