<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payment_refunds.proof_file_id gets its foreign key to stored_files (step 4.1c created the column plain, because the table did not
 * exist yet). The table is append-only by trigger but the trigger guards rows, not DDL; it is empty until step 4.3d writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table): void {
            $table->foreign('proof_file_id')->references('id')->on('stored_files')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table): void {
            $table->dropForeign(['proof_file_id']);
        });
    }
};
