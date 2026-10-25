<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, step 4.3b (plan section 3, M4): what a payment needs once every channel is taken. It runs on the live database the moment it
 * merges, where every payment is a Phase 1 cash row; their meaning does not change:
 *   - batch_uuid UUID NULL: the payments recorded together by POST /orders/{order}/payment-batches (the batch's client_uuid). NULL for one
 *     payment, as every Phase 1 row is. INDEX (branch_id, batch_uuid): a batch is answered again by it.
 *   - reference_norm VARCHAR(64) NULL: reference_no upper case without white space or dashes, what a duplicate reference is compared by
 *     (a warning, never a block: plan 10.8 R-B). INDEX (branch_id, channel_id, reference_norm), not unique. The only backfill: the Phase 1
 *     rows that carry a reference get its normalised form (normalize() below, the same rule as Payments\Support\ReferenceNumber; a
 *     migration never calls application code, ReferenceNumberTest keeps the two equal). updated_at is not touched.
 *   - receipt_file_id BIGINT UNSIGNED NULL FK stored_files: the receipt photo. receipt_photo_key is filled with the same file's key, so
 *     the Phase 1 column keeps its meaning (the object's key, signed on read).
 *   - refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0: what step 4.3d gives back of this payment; 0.00 for every existing row.
 *   - disputed_by_user_id FK users, disputed_at, dispute_reason VARCHAR(255), dispute_resolved_at: step 4.3c's; all NULL.
 *   - CHECK payments_amount_positive (amount > 0) and payments_refunded_amount_range (0 <= refunded_amount <= amount). RecordPayment never
 *     wrote a payment of no money; should the database hold one anyway, up() names the rows and stops before changing anything, rather than
 *     fail half way on an engine whose DDL does not roll back.
 * Each piece is added only when it is missing, so a second run (or a run after a half-applied one) changes nothing.
 */
return new class extends Migration
{
    private const string AMOUNT_CHECK = 'payments_amount_positive';

    private const string REFUND_CHECK = 'payments_refunded_amount_range';

    public function up(): void
    {
        if (! $this->hasCheck(self::AMOUNT_CHECK)) {
            $bad = DB::table('payments')->where('amount', '<=', 0)->orderBy('id')->limit(20)->pluck('id')->all();
            if ($bad !== []) {
                throw new RuntimeException('payments with an amount of 0 or less cannot take the CHECK amount > 0; nothing was changed. Rows: '.implode(', ', $bad));
            }
        }

        if (! Schema::hasColumn('payments', 'batch_uuid')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->uuid('batch_uuid')->nullable()->after('client_uuid');
            });
        }
        if (! Schema::hasColumn('payments', 'refunded_amount')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->decimal('refunded_amount', 12, 2)->default(0)->after('change_amount');
            });
        }
        if (! Schema::hasColumn('payments', 'reference_norm')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->string('reference_norm', 64)->nullable()->after('reference_no');
            });
        }
        if (! Schema::hasColumn('payments', 'receipt_file_id')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->foreignId('receipt_file_id')->nullable()->after('receipt_photo_key')->constrained('stored_files')->restrictOnDelete();
            });
        }
        if (! Schema::hasColumn('payments', 'disputed_by_user_id')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->foreignId('disputed_by_user_id')->nullable()->after('verified_at')->constrained('users')->restrictOnDelete();
                $table->dateTime('disputed_at')->nullable()->after('disputed_by_user_id');
                $table->string('dispute_reason', 255)->nullable()->after('disputed_at');
                $table->dateTime('dispute_resolved_at')->nullable()->after('dispute_reason');
            });
        }
        if (! Schema::hasIndex('payments', ['branch_id', 'channel_id', 'reference_norm'])) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->index(['branch_id', 'channel_id', 'reference_norm']);
            });
        }
        if (! Schema::hasIndex('payments', ['branch_id', 'batch_uuid'])) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->index(['branch_id', 'batch_uuid']);
            });
        }

        if (! $this->hasCheck(self::AMOUNT_CHECK)) {
            DB::statement('ALTER TABLE payments ADD CONSTRAINT '.self::AMOUNT_CHECK.' CHECK (amount > 0)');
        }
        if (! $this->hasCheck(self::REFUND_CHECK)) {
            DB::statement('ALTER TABLE payments ADD CONSTRAINT '.self::REFUND_CHECK.' CHECK (refunded_amount >= 0 AND refunded_amount <= amount)');
        }

        $this->backfillReferenceNorm();
    }

    public function down(): void
    {
        foreach ([self::REFUND_CHECK, self::AMOUNT_CHECK] as $check) {
            if ($this->hasCheck($check)) {
                DB::statement("ALTER TABLE payments DROP CONSTRAINT {$check}");
            }
        }
        if (Schema::hasIndex('payments', ['branch_id', 'batch_uuid'])) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->dropIndex(['branch_id', 'batch_uuid']);
            });
        }
        if (Schema::hasIndex('payments', ['branch_id', 'channel_id', 'reference_norm'])) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->dropIndex(['branch_id', 'channel_id', 'reference_norm']);
            });
        }
        if (Schema::hasColumn('payments', 'disputed_by_user_id')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('disputed_by_user_id');
                $table->dropColumn(['disputed_at', 'dispute_reason', 'dispute_resolved_at']);
            });
        }
        if (Schema::hasColumn('payments', 'receipt_file_id')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('receipt_file_id');
            });
        }
        foreach (['reference_norm', 'refunded_amount', 'batch_uuid'] as $column) {
            if (Schema::hasColumn('payments', $column)) {
                Schema::table('payments', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    /** The normalised reference: upper case, without white space, separators or dashes (Payments\Support\ReferenceNumber::normalize). */
    public function normalize(string $reference): string
    {
        return mb_strtoupper((string) preg_replace('/[\s\p{Z}\p{Pd}]+/u', '', $reference), 'UTF-8');
    }

    /** reference_norm for the rows that carry a reference and have none yet, in chunks; updated_at is left as it is. */
    private function backfillReferenceNorm(): void
    {
        DB::table('payments')->whereNotNull('reference_no')->whereNull('reference_norm')->select(['id', 'reference_no'])
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $norm = $this->normalize((string) $row->reference_no);
                    DB::table('payments')->where('id', $row->id)->update(['reference_norm' => $norm === '' ? null : mb_substr($norm, 0, 64)]);
                }
            });
    }

    private function hasCheck(string $name): bool
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return DB::table('information_schema.table_constraints')
                ->whereRaw('constraint_schema = database()')
                ->where('table_name', 'payments')
                ->where('constraint_name', $name)
                ->exists();
        }
        if ($driver === 'pgsql') {
            return DB::table('pg_constraint')->where('conname', $name)->exists();
        }

        return false;
    }
};
