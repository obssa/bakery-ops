<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Ops\Backup\BackupFolder;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The backup and drill tests of tests/Ops (step 5.5b): a backup folder, a files folder and (step 6.1b) a media folder of their own under
 * the system's temporary folder (outside the app, as on the host), a small committed ledger and a chain of two links, and the drill's
 * scratch database (SCRATCH_DB_DATABASE, default bakery_scratch) read through a probe connection of its own.
 */
final class OpsFixture
{
    public string $folder;

    public string $files;

    /** The root of the media disk: the product photos (step 6.1b, ADR 0022). */
    public string $media;

    public function __construct()
    {
        $base = sys_get_temp_dir().'/bakery-ops-test-'.bin2hex(random_bytes(6));
        $this->folder = $base.'/backups';
        $this->files = $base.'/files';
        $this->media = $base.'/media';
        mkdir($this->files.'/receipts', 0700, true);
        mkdir($this->media.'/items', 0700, true);
        config([
            'backup.path' => $this->folder,
            'backup.keep' => 30,
            'backup.max_mb' => 2048,
            'backup.remote' => null,
            'backup.drill_database' => ScratchCopy::database(),
            'files.disk' => 'files',
            'filesystems.disks.files.root' => $this->files,
            'catalog.images.disk' => 'media',
            'filesystems.disks.media.root' => $this->media,
        ]);
    }

    public function tearDown(): void
    {
        BackupFolder::removeTree(dirname($this->folder));
    }

    /** A branch with flour and sugar moved through the Ledger at its store and kitchen (no station: no shift needed). */
    public function ledger(): Branch
    {
        $branch = actInBranch(Branch::factory()->create());
        withActor(User::factory()->create(['branch_id' => $branch->id])->id);
        $flour = Item::factory()->ingredient('g')->create(['branch_id' => $branch->id])->id;
        $sugar = Item::factory()->ingredient('g')->create(['branch_id' => $branch->id])->id;
        $store = stockLocation($branch, LocationType::Store)->id;
        $kitchen = stockLocation($branch, LocationType::Kitchen)->id;
        $ledger = app(Ledger::class);
        $ledger->appendMany([MovementIntent::of($flour, $store, '100.0000', MovementReason::PurchaseIn, 'ops-test')]);
        $ledger->appendMany(MovementIntent::transfer($flour, $store, $kitchen, '40.0000', 'ops-test'));
        $ledger->appendMany([MovementIntent::of($flour, $kitchen, '-2.5000', MovementReason::Waste, 'ops-test')]);
        $ledger->appendMany([MovementIntent::of($sugar, $store, '20.0000', MovementReason::PurchaseIn, 'ops-test')]);

        return $branch;
    }

    /** Two locked counts chained at a kitchen place of a fresh branch (the roles are seeded here). */
    public function chain(TestCase $test): void
    {
        seedRoles();
        $branch = actInBranch(Branch::factory()->create());
        $f = ShiftsFixture::build($branch, $test);
        (new ChainFixture($f))->chain($f->place($f->kitchen), 2);
    }

    /** The tables of the drill's scratch database, read on a probe connection. @return list<string> */
    public function scratchTables(): array
    {
        $default = (string) config('database.default');
        config(['database.connections.ops_probe' => ['database' => ScratchCopy::database(), 'url' => null] + (array) config("database.connections.{$default}")]);
        DB::purge('ops_probe');
        $db = DB::connection('ops_probe');

        return array_values($db->getSchemaBuilder()->getTableListing($db->getDatabaseName(), false));
    }

    /** Empty the scratch database (the drill tests leave it as they found it, --keep included). */
    public function emptyScratch(): void
    {
        $this->scratchTables();
        $schema = DB::connection('ops_probe')->getSchemaBuilder();
        $schema->dropAllViews();
        $schema->dropAllTables();
    }

    /** The night's name without `.sql.gz`, for its manifest, files folder and media folder. */
    public static function base(string $dumpName): string
    {
        return substr($dumpName, 0, -strlen('.sql.gz'));
    }
}
