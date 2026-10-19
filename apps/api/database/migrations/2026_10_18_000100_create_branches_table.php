<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant (rule 8). Every other tenant table carries branch_id -> branches.id.
 * Global by definition, so it is allowlisted in scripts/check_invariants.py BRANCH_ALLOWLIST.
 *
 * `settings` is plain JSON (portable between MySQL 8 and PostgreSQL, ADR 0006) with no database default:
 * MySQL refuses literal defaults on JSON columns, so the model supplies the empty object.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('timezone', 64)->default('Africa/Addis_Ababa');
            $table->char('currency', 3)->default('ETB');
            $table->json('settings');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
