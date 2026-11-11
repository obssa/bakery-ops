<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 follow-up (the Ethiopian clock, Ramin 2026-11-10): every time of day reads in Ethiopian time ("1:50 ጠዋት"); the admin may also
 * show the international clock beside it (Admin > System > Time). clock_also VARCHAR(8) NOT NULL DEFAULT 'none', CHECK
 * brand_profiles_clock_also_check IN (none, h12, h24): none shows the Ethiopian clock alone, h12 adds "4:10 PM", h24 adds "16:10". A branch
 * without a brand_profiles row reads none; existing rows take the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('brand_profiles', 'clock_also')) {
            return;
        }

        Schema::table('brand_profiles', function (Blueprint $table): void {
            $table->string('clock_also', 8)->default('none')->after('logo_size');
        });

        DB::statement("ALTER TABLE brand_profiles ADD CONSTRAINT brand_profiles_clock_also_check CHECK (clock_also IN ('none', 'h12', 'h24'))");
    }

    public function down(): void
    {
        if (! Schema::hasColumn('brand_profiles', 'clock_also')) {
            return;
        }
        DB::statement('ALTER TABLE brand_profiles DROP CONSTRAINT brand_profiles_clock_also_check');
        Schema::table('brand_profiles', function (Blueprint $table): void {
            $table->dropColumn('clock_also');
        });
    }
};
