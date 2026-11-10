<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 follow-up (the bakery's own brand, Ramin 2026-11-10): the business name and logo the app shows in its sidebar, on the sign-in
 * screen and in the browser's title, set by the admin on the live site (Admin > System > Brand) so the public repository never holds them.
 * One row per branch (UNIQUE branch_id), created by the first save; a branch without one shows the app's own name and mark, so the table
 * starts empty and nothing is backfilled.
 *
 * - name_en / name_am (120): a bilingual pair (rule 9; the request asks for both or neither); NULL shows the app's name.
 * - logo_sha256 CHAR(64): the sha256 of the stored logo, which names its file and its public URL /api/v1/media/brand/<sha>.<ext>;
 *   logo_mime VARCHAR(16) CHECK brand_profiles_logo_mime_check IN (image/webp, image/jpeg); logo_disk VARCHAR(32), the disk's name;
 *   logo_key VARCHAR(191), the file relative to the disk; logo_size SMALLINT UNSIGNED, the square's side in pixels. All NULL without a logo.
 * - created_by_user_id: who saved it first; every change is an audit row (identity.brand.update, identity.brand.logo_set,
 *   identity.brand.logo_removed) with who, acting-as, device and when.
 * Changed in place and never deleted (no delete path), so no deleted_at (tests/Arch/SoftDeletesTest.php allowlist).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brand_profiles')) {
            return;
        }

        Schema::create('brand_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained('branches')->restrictOnDelete();
            $table->string('name_en', 120)->nullable();
            $table->string('name_am', 120)->nullable();
            $table->char('logo_sha256', 64)->nullable();
            $table->string('logo_mime', 16)->nullable();
            $table->string('logo_disk', 32)->nullable();
            $table->string('logo_key', 191)->nullable();
            $table->unsignedSmallInteger('logo_size')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('logo_sha256');
        });

        DB::statement("ALTER TABLE brand_profiles ADD CONSTRAINT brand_profiles_logo_mime_check CHECK (logo_mime IS NULL OR logo_mime IN ('image/webp', 'image/jpeg'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_profiles');
    }
};
