<?php

declare(strict_types=1);

namespace App\Console\Support;

use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Exceptions\CatalogError;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Services\ProductPhotos;
use App\Modules\Identity\Models\User;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use JsonException;

/**
 * The demo product photos (step 6.1c, phase-6 plan 4.4 and 8, ADR 0022): what `bakery:demo-product-images` and Phase6Seeder share. The
 * folder (resources/demo/product-images) holds one photo per demo product and manifest.json, a list of {slug, file, matches, source,
 * licence}: `matches` names the products by name_en, `source` is the page the photo came from and `licence` its licence (no person is
 * named: the repository is public).
 *
 * manifest() checks the whole manifest before anything is written: exactly the five keys, a slug, a file in the folder, at least one
 * name and no name in two entries, an https source page, a licence, and a JPEG, PNG or WebP photo that is 4:3 and at least
 * catalog.images.min_width x min_height (800 x 600). Any flaw refuses the run (DemoProductImagesRefused).
 *
 * plan() says per product of the branch (live products, in id order; never an ingredient) what a run does, reading only: `attach` (an
 * entry matches its name_en, without regard to case and the spaces around it, and it has no photo), `replace` (its photo is a demo one and
 * $force is set), `keep_demo` (a demo photo, no $force), `keep_upload` (a photo an admin uploaded: never touched, with or without
 * $force) or `unmatched` (no entry names it).
 *
 * apply() does it: each photo goes through Catalog's ProductPhotos, exactly as an upload does (the centre crop, the three renditions, the
 * sha256 and its files, items.image_id, the catalog_version bump and the audit row catalog.item.image_set), with origin `demo` and a new
 * client_uuid, as $actor in the branch (both put back afterwards). Each product is its own transaction, so a run that stops halfway is
 * finished by running it again. Just before each write the product is read again, so a photo uploaded while the run goes is kept.
 *
 * actor() is the person the audit rows name: the user given (of the branch, active, holding catalog.manage) or else the branch's first
 * active admin. Resolved per use (it reads the scoped CurrentBranch and ActorContext through the container), never a singleton.
 *
 * @phpstan-type Entry array{slug: string, file: string, matches: list<string>, source: string, licence: string, path: string, width: int, height: int}
 * @phpstan-type Step array{item_id: int, name_en: string, slug: string|null, action: string, error: string|null}
 */
final class DemoProductImages
{
    /** The keys of a manifest entry, in their order. */
    public const array KEYS = ['slug', 'file', 'matches', 'source', 'licence'];

    public const string ATTACH = 'attach';

    public const string REPLACE = 'replace';

    public const string KEEP_DEMO = 'keep_demo';

    public const string KEEP_UPLOAD = 'keep_upload';

    public const string UNMATCHED = 'unmatched';

    private const array IMAGE_TYPES = [IMAGETYPE_WEBP, IMAGETYPE_JPEG, IMAGETYPE_PNG];

    private readonly string $folder;

    public function __construct(?string $folder = null)
    {
        $this->folder = rtrim($folder ?? resource_path('demo/product-images'), '/\\');
    }

    /**
     * The manifest, checked whole.
     *
     * @return list<Entry>
     *
     * @throws DemoProductImagesRefused
     */
    public function manifest(): array
    {
        $path = $this->folder.'/manifest.json';
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw DemoProductImagesRefused::manifest('it is missing from the demo photos folder');
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw DemoProductImagesRefused::manifest('it is not valid JSON');
        }
        if (! is_array($decoded) || $decoded === [] || ! array_is_list($decoded)) {
            throw DemoProductImagesRefused::manifest('it must be a list of entries, one per photo');
        }

        $entries = [];
        $slugs = [];
        $names = [];
        foreach ($decoded as $index => $raw) {
            $entry = $this->entry($index + 1, $raw);
            if (isset($slugs[$entry['slug']])) {
                throw DemoProductImagesRefused::entry("entry {$slugs[$entry['slug']]}", "the slug {$entry['slug']} is listed again in entry ".($index + 1));
            }
            $slugs[$entry['slug']] = $index + 1;
            foreach ($entry['matches'] as $name) {
                $key = self::key($name);
                if (isset($names[$key])) {
                    throw DemoProductImagesRefused::entry("entry {$entry['slug']}", "\"{$name}\" is matched by {$names[$key]} too; a product has one photo");
                }
                $names[$key] = $entry['slug'];
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * The person the audit rows name.
     *
     * @throws DemoProductImagesRefused
     */
    public function actor(Branch $branch, ?int $userId = null): User
    {
        if ($userId !== null) {
            $user = $this->activeUsers($branch)->whereKey($userId)->first();
            if (! $user instanceof User || ! $user->checkPermissionTo('catalog.manage')) {
                throw DemoProductImagesRefused::actor((string) $userId, $branch);
            }

            return $user;
        }

        $admin = $this->activeUsers($branch)
            ->whereHas('roles', fn (Builder $q) => $q->where('guard_name', 'web')->where('name', 'admin'))
            ->orderBy('id')->first();

        return $admin instanceof User ? $admin : throw DemoProductImagesRefused::noAdmin($branch);
    }

    /**
     * What a run would do, product by product. Reads only: nothing is written, not even a file.
     *
     * @param  list<Entry>|null  $manifest  checked already (manifest()); read and checked here when null
     * @return list<Step>
     *
     * @throws DemoProductImagesRefused
     */
    public function plan(Branch $branch, bool $force = false, ?array $manifest = null): array
    {
        $manifest ??= $this->manifest();

        return app(CurrentBranch::class)->runAs($branch->id, fn (): array => $this->steps($branch, $force, $manifest));
    }

    /**
     * Attaches (and with $force replaces) the photos plan() names, as $actor. A step whose photo was refused carries the refusal's
     * message_key in `error`; the others go on.
     *
     * @return list<Step>
     *
     * @throws DemoProductImagesRefused before anything is written
     */
    public function apply(Branch $branch, User $actor, bool $force = false): array
    {
        $manifest = $this->manifest();
        $files = array_column($manifest, 'path', 'slug');
        $stamp = ['user_id' => $actor->id, 'acting_user_id' => null, 'shift_instance_id' => null, 'station_id' => null, 'device_id' => null];

        return app(CurrentBranch::class)->runAs($branch->id, fn (): array => app(ActorContext::class)->runAs($stamp, function () use ($branch, $force, $manifest, $files): array {
            $steps = $this->steps($branch, $force, $manifest);
            foreach ($steps as $i => $step) {
                if (! in_array($step['action'], [self::ATTACH, self::REPLACE], true) || $step['slug'] === null) {
                    continue;
                }
                $item = Item::query()->with('image')->find($step['item_id']);
                $now = $item instanceof Item ? $this->action($item, true, $force) : self::UNMATCHED;
                if ($now !== $step['action'] || ! $item instanceof Item) {
                    $steps[$i]['action'] = $now; // changed while the run went (a photo uploaded, the product removed): left alone

                    continue;
                }
                $bytes = file_get_contents($files[$step['slug']]);
                try {
                    if ($bytes === false) {
                        throw CatalogError::imageNotAnImage();
                    }
                    app(ProductPhotos::class)->set($item, $bytes, null, (string) Str::uuid(), 'demo');
                } catch (CatalogError $e) {
                    $steps[$i]['error'] = $e->messageKey;
                }
            }

            return $steps;
        }));
    }

    /**
     * The counts a run reports.
     *
     * @param  list<Step>  $steps
     * @return array{products: int, matched: int, attach: int, replace: int, skipped: int, unmatched: int, failed: int}
     */
    public static function counts(array $steps): array
    {
        $count = fn (string ...$actions): int => count(array_filter($steps, fn (array $s): bool => in_array($s['action'], $actions, true)));
        $done = fn (string $action): int => count(array_filter($steps, fn (array $s): bool => $s['action'] === $action && $s['error'] === null));

        return [
            'products' => count($steps),
            'matched' => count($steps) - $count(self::UNMATCHED),
            'attach' => $done(self::ATTACH),
            'replace' => $done(self::REPLACE),
            'skipped' => $count(self::KEEP_DEMO, self::KEEP_UPLOAD),
            'unmatched' => $count(self::UNMATCHED),
            'failed' => count(array_filter($steps, fn (array $s): bool => $s['error'] !== null)),
        ];
    }

    /**
     * The slugs of the entries no product of the run matched.
     *
     * @param  list<Entry>  $manifest
     * @param  list<Step>  $steps
     * @return list<string>
     */
    public static function unused(array $manifest, array $steps): array
    {
        return array_values(array_diff(array_column($manifest, 'slug'), array_filter(array_column($steps, 'slug'))));
    }

    /**
     * @param  list<Entry>  $manifest
     * @return list<Step>
     */
    private function steps(Branch $branch, bool $force, array $manifest): array
    {
        $byName = [];
        foreach ($manifest as $entry) {
            foreach ($entry['matches'] as $name) {
                $byName[self::key($name)] = $entry['slug'];
            }
        }

        $products = Item::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $branch->id)
            ->where('kind', ItemKind::Product)->with('image')->orderBy('id')->get();
        $steps = [];
        foreach ($products as $item) {
            $slug = $byName[self::key($item->name_en)] ?? null;
            $steps[] = [
                'item_id' => $item->id,
                'name_en' => $item->name_en,
                'slug' => $slug,
                'action' => $this->action($item, $slug !== null, $force),
                'error' => null,
            ];
        }

        return $steps;
    }

    /** @return Builder<User> the branch's active (and not removed) users; the branch is named, not taken from the scope */
    private function activeUsers(Branch $branch): Builder
    {
        return User::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $branch->id)->where('is_active', true);
    }

    private function action(Item $item, bool $matched, bool $force): string
    {
        return match (true) {
            ! $matched || $item->trashed() || ! $item->isProduct() => self::UNMATCHED,
            $item->image_id === null => self::ATTACH,
            // a photo whose row cannot be read is treated as an upload: never touched
            $item->image?->origin !== 'demo' => self::KEEP_UPLOAD,
            $force => self::REPLACE,
            default => self::KEEP_DEMO,
        };
    }

    /**
     * One entry, checked.
     *
     * @return Entry
     *
     * @throws DemoProductImagesRefused
     */
    private function entry(int $number, mixed $raw): array
    {
        if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw DemoProductImagesRefused::entry("entry {$number}", 'it is not an object of '.implode(', ', self::KEYS));
        }
        $label = is_string($raw['slug'] ?? null) ? "entry {$number} ({$raw['slug']})" : "entry {$number}";
        $keys = array_map('strval', array_keys($raw));
        if (count($keys) !== count(self::KEYS) || array_diff(self::KEYS, $keys) !== []) {
            throw DemoProductImagesRefused::entry($label, 'the keys must be exactly '.implode(', ', self::KEYS).', not '.implode(', ', $keys));
        }

        $slug = $raw['slug'];
        if (! is_string($slug) || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
            throw DemoProductImagesRefused::entry($label, 'the slug must be lower-case words and digits joined by hyphens');
        }
        $file = $raw['file'];
        if (! is_string($file) || preg_match('/^[a-z0-9][a-z0-9_-]*\.(webp|jpg|jpeg|png)$/', $file) !== 1) {
            throw DemoProductImagesRefused::entry($label, 'the file must name a photo in the folder itself (.webp, .jpg or .png)');
        }
        $matches = $raw['matches'];
        if (! is_array($matches) || $matches === [] || ! array_is_list($matches)) {
            throw DemoProductImagesRefused::entry($label, 'matches must list the English names (name_en) of the products it is the photo of');
        }
        $names = [];
        foreach ($matches as $name) {
            if (! is_string($name) || trim($name) === '') {
                throw DemoProductImagesRefused::entry($label, 'every name in matches must be a product\'s English name');
            }
            $names[] = trim($name);
        }
        $source = $raw['source'];
        if (! is_string($source) || ! str_starts_with($source, 'https://') || filter_var($source, FILTER_VALIDATE_URL) === false) {
            throw DemoProductImagesRefused::entry($label, 'there is no source page (the https address of the page the photo was taken from)');
        }
        $licence = $raw['licence'];
        if (! is_string($licence) || trim($licence) === '') {
            throw DemoProductImagesRefused::entry($label, 'there is no licence (the name of the licence the photo is under)');
        }

        $path = $this->folder.'/'.$file;
        if (! is_file($path)) {
            throw DemoProductImagesRefused::entry($label, "{$file} is not in the folder");
        }
        set_error_handler(static fn (): bool => true); // a file that is not an image warns; it is refused below
        try {
            $size = getimagesize($path);
        } finally {
            restore_error_handler();
        }
        if ($size === false || ! in_array($size[2], self::IMAGE_TYPES, true)) {
            throw DemoProductImagesRefused::entry($label, "{$file} is not a JPEG, PNG or WebP photo");
        }
        [$width, $height] = [$size[0], $size[1]];
        if ($width * 3 !== $height * 4) {
            throw DemoProductImagesRefused::entry($label, "{$file} is {$width} x {$height}, not 4:3");
        }
        $minWidth = (int) config('catalog.images.min_width', 800);
        $minHeight = (int) config('catalog.images.min_height', 600);
        if ($width < $minWidth || $height < $minHeight) {
            throw DemoProductImagesRefused::entry($label, "{$file} is {$width} x {$height}, under {$minWidth} x {$minHeight}");
        }

        return [
            'slug' => $slug,
            'file' => $file,
            'matches' => $names,
            'source' => $source,
            'licence' => trim($licence),
            'path' => $path,
            'width' => $width,
            'height' => $height,
        ];
    }

    /** How a name is matched: without regard to case or the spaces around it. */
    private static function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
