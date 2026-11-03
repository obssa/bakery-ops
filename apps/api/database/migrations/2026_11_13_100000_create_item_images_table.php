<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 6.1a, phase-6 plan section 3 M1 (ADR 0022): the display photo of a product, one row per photo set, written by
 * App\Modules\Catalog\Services\ProductPhotos and pointed at by items.image_id (M2).
 *   sha256      CHAR(64): the sha256 of the stored master, the `large` rendition's bytes; it names the files and the public URL;
 *   width, height  SMALLINT UNSIGNED: the `large` rendition's size (the web's srcSet reads it);
 *   mime        VARCHAR(16), CHECK item_images_mime_check IN (image/webp, image/jpeg): the format of all three renditions;
 *   bytes       INT UNSIGNED: the three renditions together, what the disk holds for the row (the System page sums it, 6.1b);
 *   disk        VARCHAR(32): the disk's name (`media`), never a path;
 *   key_prefix  VARCHAR(191): items/<sha[0..1]>/<sha>, relative to the disk, so the files are <key_prefix>/<rendition>.<ext>;
 *   origin      VARCHAR(8) DEFAULT upload, CHECK item_images_origin_check IN (upload, demo): demo photos are the only ones the demo
 *               command (6.1c) may replace;
 *   uploaded_by_user_id and the actor stamp (rule 4), NULL for a demo photo; client_uuid UNIQUE with branch_id (rule 7).
 * Not append-only: a row changes only by its soft delete (a replace or a remove); who did it is in audit_log. Indexes (branch_id, item_id)
 * for a product's history and (sha256, deleted_at) for the media route's one lookup. A new table: no live row is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->char('sha256', 64);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->string('mime', 16);
            $table->unsignedInteger('bytes');
            $table->string('disk', 32);
            $table->string('key_prefix', 191);
            $table->string('origin', 8)->default('upload');
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('shift_instance_id')->nullable()->constrained('shift_instances')->restrictOnDelete();
            $table->foreignId('station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'client_uuid']);
            $table->index(['branch_id', 'item_id']);
            $table->index(['sha256', 'deleted_at']);
        });

        DB::statement("ALTER TABLE item_images ADD CONSTRAINT item_images_mime_check CHECK (mime IN ('image/webp', 'image/jpeg'))");
        DB::statement("ALTER TABLE item_images ADD CONSTRAINT item_images_origin_check CHECK (origin IN ('upload', 'demo'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('item_images');
    }
};
