<?php

declare(strict_types=1);

use App\Shared\Files\Files;
use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Contract: payments -> files (phase-4 plan section 2 and 10.9 item 1; manifest contracts["payments->files"], added by step 4.1a).
 *
 * Written BEFORE `App\Shared\Files` exists (step 4.2a builds it): RED now because the class is missing. Payments consumes only
 *   Files::put(UploadedFile|string $bytes, string $purpose, string $clientUuid): StoredFile   (->id, ->key, ->width, ->height, ->bytes)
 *   Files::signedUrl(StoredFile|string $key, ?int $ttlSeconds = null): string
 * and relies on Shared to strip metadata, shrink, store privately and sign. The tests read the stored bytes with a small JPEG segment
 * walker written here (an independent reader: it shares no code with the ImageProcessor). Refusals are FilesError exceptions with a
 * `messageKey` (files.not_an_image, files.truncated, files.too_many_pixels, files.too_large). Nothing is world-readable.
 * No CI job runs this group before step 4.2a (mod-payments gets it then).
 */

uses()->group('contract:payments->files');

/** The marker bytes of every segment of a JPEG, in order, up to the start of scan. @return list<int> */
function jpegSegments(string $bytes): array
{
    expect(substr($bytes, 0, 2))->toBe("\xFF\xD8", 'a JPEG starts with SOI');
    $markers = [];
    $i = 2;
    $length = strlen($bytes);
    while ($i + 4 <= $length) {
        if ($bytes[$i] !== "\xFF") {
            break;
        }
        $marker = ord($bytes[$i + 1]);
        $markers[] = $marker;
        if ($marker === 0xDA) { // start of scan: the entropy-coded data follows
            break;
        }
        $i += 2 + unpack('n', substr($bytes, $i + 2, 2))[1];
    }

    return $markers;
}

/** A JPEG of $w x $h with an EXIF APP1 block (orientation, and a GPS IFD) and a fake ICC APP2 block spliced in after SOI. */
function jpegWithMetadata(int $w, int $h, int $orientation = 1): string
{
    $image = imagecreatetruecolor($w, $h);
    imagefilledrectangle($image, 0, 0, intdiv($w, 2), $h, imagecolorallocate($image, 200, 40, 40));
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();
    // TIFF, big endian: IFD0 at 8 with Orientation (0x0112) and GPSInfo (0x8825 -> IFD at 38); the GPS IFD holds GPSLatitudeRef "N"
    $tiff = "MM\x00\x2A\x00\x00\x00\x08"
        .pack('n', 2)
        .pack('nnN', 0x0112, 3, 1).pack('n', $orientation)."\x00\x00"
        .pack('nnNN', 0x8825, 4, 1, 38)
        ."\x00\x00\x00\x00"
        .pack('n', 1).pack('nnN', 1, 2, 2)."N\x00\x00\x00"."\x00\x00\x00\x00";
    $exif = "Exif\x00\x00".$tiff;
    $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
    $icc = "ICC_PROFILE\x00\x01\x01".str_repeat("\x00", 40);
    $app2 = "\xFF\xE2".pack('n', strlen($icc) + 2).$icc;

    return "\xFF\xD8".$app1.$app2.substr($jpeg, 2);
}

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->branch = actInBranch(Branch::factory()->create());
    seedRoles();
    withActor(makeUser($this->branch, 'cashier')->id);
    $this->files = fn (): Files => app(Files::class);
    $this->stored = fn ($file): string => Storage::disk(config('files.disk'))->get($file->key);
    // the message_key of the refusal a call throws
    $this->refusal = function (Closure $call): ?string {
        try {
            $call();
        } catch (Throwable $e) {
            if (! property_exists($e, 'messageKey')) {
                throw $e;
            }

            return $e->messageKey;
        }

        return null;
    };
});

it('strips every metadata block, applies the orientation and shrinks the longest side to 1600 keeping the aspect', function () {
    $input = jpegWithMetadata(4000, 3000, 6); // rotated 90 degrees clockwise when shown: 3000 wide x 4000 high
    expect(jpegSegments($input))->toContain(0xE1)->toContain(0xE2); // the fixture really carries EXIF/GPS and ICC

    $file = ($this->files)()->put($input, 'receipt', (string) Str::uuid());
    $bytes = ($this->stored)($file);
    [$width, $height] = getimagesizefromstring($bytes);

    expect(jpegSegments($bytes))->not->toContain(0xE1)->not->toContain(0xE2)->not->toContain(0xED) // no EXIF/GPS, no ICC, no Photoshop
        ->and(str_contains($bytes, 'Exif'))->toBeFalse()
        ->and([$width, $height])->toBe([1200, 1600], 'orientation applied, longest side 1600, aspect 3:4 kept')
        ->and([$file->width, $file->height])->toBe([1200, 1600])
        ->and($file->key)->toStartWith("receipts/{$this->branch->id}/")->toEndWith('.jpg');
});

it('flattens a PNG with alpha onto white and stores it as a JPEG', function () {
    $image = imagecreatetruecolor(300, 200);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127)); // fully transparent
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    $file = ($this->files)()->put($png, 'receipt', (string) Str::uuid());
    $bytes = ($this->stored)($file);

    expect(getimagesizefromstring($bytes)['mime'])->toBe('image/jpeg');
    $pixel = imagecolorsforindex($decoded = imagecreatefromstring($bytes), imagecolorat($decoded, 10, 10));
    expect($pixel['red'])->toBeGreaterThan(240)->and($pixel['green'])->toBeGreaterThan(240)->and($pixel['blue'])->toBeGreaterThan(240);
});

it('refuses a file that is not an image, a truncated image, a 25 MP image and a file over max_bytes, each with its own key, and stores nothing', function () {
    $valid = jpegWithMetadata(800, 600);
    $big = imagecreatetruecolor(5000, 5000); // 25 MP
    ob_start();
    imagejpeg($big, null, 5);
    $tooManyPixels = (string) ob_get_clean();
    config(['files.max_bytes' => 100_000]);
    $tooLarge = $valid.str_repeat("\x00", 120_000);

    $cases = [
        'files.not_an_image' => 'this is not an image, it is a sentence',
        'files.truncated' => substr($valid, 0, intdiv(strlen($valid), 2)),
        'files.too_many_pixels' => $tooManyPixels,
        'files.too_large' => $tooLarge,
    ];
    foreach ($cases as $key => $bytes) {
        $thrown = ($this->refusal)(fn () => ($this->files)()->put($bytes, 'receipt', (string) Str::uuid()));
        expect($thrown)->toBe($key);
    }

    expect(Storage::disk(config('files.disk'))->allFiles())->toBe([])
        ->and(DB::table('stored_files')->count())->toBe(0);
});

it('signs a URL that verifies until the ttl and not after, and fails when any query parameter is changed', function () {
    $file = ($this->files)()->put(jpegWithMetadata(600, 400), 'receipt', (string) Str::uuid());
    $url = ($this->files)()->signedUrl($file, 60);

    $this->get($url)->assertOk()->assertHeader('Cache-Control')->assertHeader('Referrer-Policy', 'no-referrer');
    $parts = parse_url($url);
    parse_str($parts['query'], $query);
    foreach (array_keys($query) as $param) {
        $changed = $query;
        $changed[$param] = $changed[$param].'0';
        $this->get($parts['path'].'?'.http_build_query($changed))->assertForbidden();
    }

    $this->travel(61)->seconds();
    $this->get($url)->assertForbidden();
});

it('never signs another branch\'s key', function () {
    $file = ($this->files)()->put(jpegWithMetadata(600, 400), 'receipt', (string) Str::uuid());
    $other = actInBranch(Branch::factory()->create());
    withActor(makeUser($other, 'cashier')->id);

    expect(fn () => ($this->files)()->signedUrl($file->key))->toThrow(ModelNotFoundException::class);
});

it('returns the same row and stores one object when the same client_uuid is put twice', function () {
    $uuid = (string) Str::uuid();

    $first = ($this->files)()->put(jpegWithMetadata(600, 400), 'receipt', $uuid);
    $second = ($this->files)()->put(jpegWithMetadata(600, 400), 'receipt', $uuid);

    expect($second->id)->toBe($first->id)
        ->and($second->key)->toBe($first->key)
        ->and(DB::table('stored_files')->where('client_uuid', $uuid)->count())->toBe(1)
        ->and(Storage::disk(config('files.disk'))->allFiles())->toHaveCount(1);
});

it('keeps every object private: outside public/, and a plain GET of its URL path without a signature is not served', function () {
    $file = ($this->files)()->put(UploadedFile::fake()->createWithContent('r.jpg', jpegWithMetadata(600, 400)), 'receipt', (string) Str::uuid());
    $path = parse_url(($this->files)()->signedUrl($file), PHP_URL_PATH);

    expect(Storage::disk(config('files.disk'))->exists($file->key))->toBeTrue()
        ->and(config('filesystems.disks.'.config('files.disk').'.visibility'))->toBe('private')
        ->and((string) config('filesystems.disks.'.config('files.disk').'.root'))->not->toContain(public_path())
        ->and(file_exists(public_path($file->key)))->toBeFalse();
    $this->get($path)->assertForbidden();
    $this->get('/'.$file->key)->assertNotFound();
});

it('never writes the key or the signed URL to the log', function () {
    $lines = [];
    Log::listen(function ($event) use (&$lines): void {
        $lines[] = $event->message.' '.json_encode($event->context);
    });

    $file = ($this->files)()->put(jpegWithMetadata(600, 400), 'receipt', (string) Str::uuid());
    $url = ($this->files)()->signedUrl($file);
    $this->get($url)->assertOk();
    ($this->refusal)(fn () => ($this->files)()->put('not an image', 'receipt', (string) Str::uuid()));

    $log = implode("\n", $lines);
    expect($log)->not->toContain($file->key)->not->toContain('signature=')->not->toContain(basename($file->key));
});
