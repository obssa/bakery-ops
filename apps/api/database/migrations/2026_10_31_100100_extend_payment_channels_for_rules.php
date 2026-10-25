<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, step 4.3a (plan section 3, M3): what the admin may now say about a payment channel beyond Phase 1's requires_photo and
 * requires_reference. Additive only, every column NULL with no default, nothing backfilled, so each channel the live database
 * already has (the eight defaults of every branch, some edited by hand) keeps exactly its meaning:
 *   - client_uuid UUID NULL: the client's id of a channel created through POST /payment-channels (a retry answers the same row);
 *     the defaults were never created by a client and stay NULL. UNIQUE (branch_id, client_uuid): NULLs never collide.
 *   - reference_pattern VARCHAR(120) NULL: a regex body without delimiters a reference must match as a whole, validated when saved
 *     (Payments\Support\ReferencePattern). NULL = any non-empty reference passes, which is today's rule. No pattern is seeded: nobody
 *     here knows each bank's current format.
 *   - reference_hint_en / _am and photo_hint_en / _am VARCHAR(120) NULL: what the till shows next to the reference field and the
 *     camera button, as bilingual pairs (rule 9). NULL = no hint, as today.
 * Each piece is added only when it is missing, so a second run (or a run after a half-applied one on a non-transactional DDL engine)
 * changes nothing. The cash channel needs no column: it is the row with code `cash`, as Phase 1's Totals and RecordPayment read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payment_channels', 'reference_pattern')) {
            Schema::table('payment_channels', function (Blueprint $table): void {
                $table->uuid('client_uuid')->nullable()->after('branch_id');
                $table->string('reference_pattern', 120)->nullable()->after('requires_reference');
                $table->string('reference_hint_en', 120)->nullable()->after('reference_pattern');
                $table->string('reference_hint_am', 120)->nullable()->after('reference_hint_en');
                $table->string('photo_hint_en', 120)->nullable()->after('reference_hint_am');
                $table->string('photo_hint_am', 120)->nullable()->after('photo_hint_en');
            });
        }
        if (! Schema::hasIndex('payment_channels', ['branch_id', 'client_uuid'], 'unique')) {
            Schema::table('payment_channels', function (Blueprint $table): void {
                $table->unique(['branch_id', 'client_uuid']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('payment_channels', ['branch_id', 'client_uuid'], 'unique')) {
            Schema::table('payment_channels', function (Blueprint $table): void {
                $table->dropUnique(['branch_id', 'client_uuid']);
            });
        }
        if (Schema::hasColumn('payment_channels', 'reference_pattern')) {
            Schema::table('payment_channels', function (Blueprint $table): void {
                $table->dropColumn(['client_uuid', 'reference_pattern', 'reference_hint_en', 'reference_hint_am', 'photo_hint_en', 'photo_hint_am']);
            });
        }
    }
};
