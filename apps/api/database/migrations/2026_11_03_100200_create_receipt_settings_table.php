<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M5 (phase-4 plan section 3, step 4.3e): what a branch's receipts print at the top and the bottom. One row per branch (UNIQUE branch_id),
 * created by the first PUT /receipt-settings; a branch without one reads the defaults (its name in both languages, no address, phone, TIN
 * or footer, Amharic shown, Ethiopian dates off), so the table starts empty on the live database and nothing is backfilled.
 *
 * - business_name_en / _am (120), address_en / _am (255), footer_en / _am (255): bilingual pairs (rule 9; the request asks for both or neither).
 * - phone (32) and tin (32): printed as typed; the TIN is the tax number the receipt shows next to the business name (10.8 R-P: no VAT is
 *   computed, the receipt prints the tax the order carries).
 * - show_amharic: the default language of a receipt (both when on, English when off); ethiopian_dates: print the Ethiopian date too.
 * - created_by_user_id: who saved it first; every save is an audit row (receipt_settings.update) with before and after.
 * Changed in place and never deleted (no delete path), so no deleted_at (tests/Arch/SoftDeletesTest.php allowlist).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('receipt_settings')) {
            return;
        }

        Schema::create('receipt_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained('branches')->restrictOnDelete();
            $table->string('business_name_en', 120);
            $table->string('business_name_am', 120);
            $table->string('address_en', 255)->nullable();
            $table->string('address_am', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('tin', 32)->nullable();
            $table->string('footer_en', 255)->nullable();
            $table->string('footer_am', 255)->nullable();
            $table->boolean('show_amharic')->default(true);
            $table->boolean('ethiopian_dates')->default(false);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_settings');
    }
};
