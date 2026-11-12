<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEMPORARY (ADR 0025, Ramin 2026-11-12): the switch of the sign-in page's one-click demo buttons, which the demo branch's admin turns on
 * and off in Admin > System. demo_login BOOLEAN NOT NULL DEFAULT false on the demo branch's brand_profiles row: off unless the admin turns
 * it on; a branch without a row reads off, and existing rows take the default. The server's BAKERY_DEMO_LOGIN=false still forces it off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('brand_profiles', 'demo_login')) {
            return;
        }

        Schema::table('brand_profiles', function (Blueprint $table): void {
            $table->boolean('demo_login')->default(false)->after('clock_also');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('brand_profiles', 'demo_login')) {
            return;
        }
        Schema::table('brand_profiles', function (Blueprint $table): void {
            $table->dropColumn('demo_login');
        });
    }
};
