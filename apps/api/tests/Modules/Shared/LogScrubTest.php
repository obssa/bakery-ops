<?php

declare(strict_types=1);

use App\Shared\Files\LogScrubProcessor;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;

/*
 * Step 4.2b (plan 10.1 R-F): a signed URL is a bearer credential for its life, so it never reaches a log. LogScrubProcessor removes
 * `signature=` and `expires=` values from any logged text or context, and never lets an object key through.
 */

uses()->group('module:shared', 'feature');

/** @return array{message: string, context: array<string, mixed>} */
function scrubbed(string $message, array $context = []): array
{
    $handler = new TestHandler;
    $logger = new Logger('t', [$handler], [new LogScrubProcessor]);
    $logger->log(Level::Info, $message, $context);
    $record = $handler->getRecords()[0];

    return ['message' => $record->message, 'context' => $record->context];
}

it('removes signature and expires from a URL in the message', function () {
    $out = scrubbed('GET http://localhost/api/v1/files/7?expires=1893456000&signature=abcdef0123456789 failed');

    expect($out['message'])->not->toContain('abcdef0123456789')->not->toContain('1893456000')->toContain('/api/v1/files/7')->toContain('failed');
});

it('removes them wherever they sit in the query string, and the S3 presigned parameters', function () {
    $url = 'http://minio:9000/bakery/a.jpg?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=deadbeef&X-Amz-Expires=300&signature=s1&foo=bar&expires=99';
    $out = scrubbed("fetching {$url}");

    expect($out['message'])->not->toContain('deadbeef')->not->toContain('=s1')->not->toContain('expires=99')->not->toContain('X-Amz-Expires=300')->toContain('foo=bar');
});

it('scrubs strings nested in the context but leaves other values alone', function () {
    $out = scrubbed('plain message', ['url' => 'http://x/y?signature=SECRET', 'nested' => ['u' => 'http://x?expires=5&a=1'], 'count' => 3, 'name' => 'bread']);

    expect($out['context']['url'])->not->toContain('SECRET')
        ->and($out['context']['nested']['u'])->not->toContain('expires=5')->toContain('a=1')
        ->and($out['context']['count'])->toBe(3)->and($out['context']['name'])->toBe('bread')
        ->and($out['message'])->toBe('plain message');
});

it('never logs an object key', function () {
    $key = 'receipts/12/2026/10/0a1b2c3d-0a1b-4c3d-8e9f-0a1b2c3d4e5f.jpg';
    $out = scrubbed("could not read {$key} from the disk", ['key' => $key]);

    expect($out['message'])->not->toContain('receipts/12')->not->toContain('0a1b2c3d')->toContain('could not read')
        ->and(json_encode($out['context']))->not->toContain('receipts/12');
});

it('is installed on the default log channels', function () {
    foreach (['single', 'daily', 'stderr', 'errorlog', 'stack'] as $channel) {
        $logger = app('log')->channel($channel)->getLogger();
        $processors = $logger instanceof Logger ? $logger->getProcessors() : [];
        expect(array_filter($processors, fn ($p) => $p instanceof LogScrubProcessor))->not->toBeEmpty("channel {$channel}");
    }
});

it('scrubs a real line written through a configured channel', function () {
    $path = storage_path('logs/scrub-test.log');
    @unlink($path);
    config(['logging.channels.scrub_test' => ['driver' => 'single', 'path' => $path, 'replace_placeholders' => true, 'tap' => config('logging.channels.single.tap')]]);

    app('log')->channel('scrub_test')->info('url http://h/f?expires=1&signature=LEAKME');

    $content = (string) file_get_contents($path);
    @unlink($path);
    expect($content)->toContain('url http://h/f')->not->toContain('LEAKME');
});
