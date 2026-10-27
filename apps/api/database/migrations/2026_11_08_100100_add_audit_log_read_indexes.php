<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M17 (phase-4 plan section 3, step 4.5a): the read indexes of the audit-log screen (GET /audit-log, newest first by id, keyset paged):
 * (branch_id, id) for the plain page, (branch_id, action, id) for the action prefix, (branch_id, subject_type, subject_id, id) for a
 * subject's history, (branch_id, actor_id, id) and (branch_id, acting_user_id, id) for a person, whom the filter finds in either column.
 * Indexes only: audit_log holds real rows on the live host since Phase 0 and is append-only (its triggers refuse an update or a delete),
 * and adding a secondary index rewrites no row (InnoDB builds it in place); the triggers are not touched. (branch_id, at) and
 * (subject_type, subject_id) of Phase 0 stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->index(['branch_id', 'id']);
            $table->index(['branch_id', 'action', 'id']);
            $table->index(['branch_id', 'subject_type', 'subject_id', 'id']);
            $table->index(['branch_id', 'actor_id', 'id']);
            $table->index(['branch_id', 'acting_user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'id']);
            $table->dropIndex(['branch_id', 'action', 'id']);
            $table->dropIndex(['branch_id', 'subject_type', 'subject_id', 'id']);
            $table->dropIndex(['branch_id', 'actor_id', 'id']);
            $table->dropIndex(['branch_id', 'acting_user_id', 'id']);
        });
    }
};
