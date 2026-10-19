<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stations: where order lines route and who counts what (domain-model.md, Shared). The six codes are fixed
 * (App\Shared\Enums\StationCode); the database refuses anything else through a named CHECK constraint, which
 * MySQL 8.0.16+ and PostgreSQL both enforce (ADR 0006). tests/Modules/Shared/SharedSchemaTest.php keeps the
 * constraint and the enum identical.
 */
return new class extends Migration
{
    private const array CODES = ['till', 'kitchen', 'bar', 'cake_room', 'bakery', 'store'];

    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name_en', 120);
            $table->string('name_am', 120);
            $table->boolean('requires_overseer')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'code']);
        });

        $codes = implode(', ', array_map(static fn (string $code): string => "'{$code}'", self::CODES));
        DB::statement("ALTER TABLE stations ADD CONSTRAINT stations_code_check CHECK (code IN ({$codes}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('stations');
    }
};
