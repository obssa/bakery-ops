<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may count what and who co-signs (domain-model.md, Identity): one row per (user, station). Replacing a user's
 * assignments soft-deletes the rows that go and restores the ones that come back, so the unique pair holds across
 * the row's whole life (rule 4: nothing is deleted in place).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_station_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('station_id')->constrained('stations')->restrictOnDelete();
            $table->boolean('can_count')->default(false);
            $table->boolean('is_overseer')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'station_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_station_assignments');
    }
};
