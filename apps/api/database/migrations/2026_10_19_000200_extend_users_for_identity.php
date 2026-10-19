<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users becomes the Identity table (domain-model.md, Identity; step 0.4): branch_id (rule 8), phone as the login id
 * (unique per branch), the short-code state (argon2id hash, failed attempts, lock), locale en|am, activity, photo
 * and soft deletes (rule 4). Email becomes optional. The scaffold table already exists on the live host, so this is
 * a Schema::table extension, not a rewrite; scripts/check_invariants.py --branch-id recognises branch_id added here.
 *
 * The scaffold shape could hold no actual user (there was no login before this step), so pre-Identity rows cannot be
 * given a branch or a phone: the migration refuses to run while any exist instead of guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('users')->exists()) {
            throw new RuntimeException('users has rows from before the Identity module; delete them (they could never log in) and migrate again.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_email_unique');
            $table->dropColumn('email_verified_at');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('branch_id')->after('id')->constrained('branches')->restrictOnDelete();
            $table->string('phone', 20)->after('name');
            $table->string('email')->nullable()->change();
            $table->string('short_code_hash', 255)->nullable()->after('password');
            $table->unsignedSmallInteger('short_code_attempts')->default(0)->after('short_code_hash');
            $table->timestamp('pin_locked_until')->nullable()->after('short_code_attempts');
            $table->char('locale', 2)->default('en')->after('pin_locked_until');
            $table->boolean('is_active')->default(true)->after('locale');
            $table->string('photo_url', 2048)->nullable()->after('is_active');
            $table->softDeletes();

            $table->unique(['branch_id', 'phone']);
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_locale_check CHECK (locale IN ('en', 'am'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_locale_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['branch_id', 'phone']);
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['phone', 'short_code_hash', 'short_code_attempts', 'pin_locked_until', 'locale', 'is_active', 'photo_url', 'deleted_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->unique()->change();
            $table->timestamp('email_verified_at')->nullable();
        });
    }
};
