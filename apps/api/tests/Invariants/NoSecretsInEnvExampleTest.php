<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
 * Phase 2 plan sections 7 and 10.1: web push and Telegram ship OFF and their secrets exist only in the server's .env. The
 * repository is public, so this fails the build if a key or a token ever lands in it:
 *   - .env.example and .env.ci list the driver settings with empty values (or leave them out); only the CI array driver may
 *     be switched on, and only in .env.ci;
 *   - nothing under apps/ or docs/ contains a PEM block, a Telegram bot token, or a VAPID-key-shaped string next to the word
 *     vapid (the tests build their keys at run time and keep no literal);
 *   - the runbook names every setting and gives none a value.
 * The detectors are themselves tested against samples built here, so a pattern that silently stopped matching fails too.
 */

const SECRET_SETTINGS = [
    'NOTIFY_PUSH_ENABLED', 'VAPID_SUBJECT', 'VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY',
    'NOTIFY_TELEGRAM_ENABLED', 'TELEGRAM_BOT_TOKEN', 'TELEGRAM_BOT_USERNAME', 'TELEGRAM_WEBHOOK_SECRET',
];

function repoRoot(): string
{
    return dirname(__DIR__, 4); // tests/Invariants -> tests -> apps/api -> apps -> repo root
}

/** @return array<string, string> the KEY=value lines of an env file (comments and blanks skipped), values unquoted */
function envValues(string $file): array
{
    $values = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim(trim($value), "\"'");
    }

    return $values;
}

/** @return list<string> text files under the repository's apps/ and docs/ that are ours: no vendor, no build output */
function committedTextFiles(): array
{
    $skip = ['vendor', 'node_modules', '.next', 'out', 'storage', 'tmp', '.git', 'coverage', 'playwright-report', 'test-results', 'cache', 'build'];
    $extensions = ['php', 'ts', 'tsx', 'js', 'mjs', 'json', 'md', 'yml', 'yaml', 'sh', 'txt', 'toml', 'example', 'ci', 'env', 'dbml', 'py', 'html'];
    $files = [];
    foreach (['apps', 'docs'] as $top) {
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(repoRoot().'/'.$top, FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $file): bool => ! $file->isDir() || ! in_array($file->getFilename(), $skip, true),
        ));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true) && $file->getSize() < 4_000_000) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

/** @return list<string> what in $text looks like a secret, one label per finding */
function secretsIn(string $text): array
{
    $found = [];
    if (preg_match('/-----BEGIN [A-Z ]*(PRIVATE KEY|PUBLIC KEY|CERTIFICATE)-----/', $text) === 1) {
        $found[] = 'a PEM block';
    }
    if (preg_match('/(?<![A-Za-z0-9])\d{8,10}:[A-Za-z0-9_-]{35}(?![A-Za-z0-9_-])/', $text) === 1) {
        $found[] = 'a Telegram bot token';
    }
    if (preg_match('/vapid[^\r\n]*?[\'"=:\s]([A-Za-z0-9_-]{87}|[A-Za-z0-9_-]{43})(?![A-Za-z0-9_-])/i', $text) === 1) {
        $found[] = 'a VAPID key';
    }

    return $found;
}

it('lists the driver settings in .env.example with empty values', function () {
    $values = envValues(dirname(__DIR__, 2).'/.env.example');

    foreach (SECRET_SETTINGS as $name) {
        expect(array_key_exists($name, $values))->toBeTrue("{$name} is listed in .env.example")
            ->and($values[$name])->toBe('', "{$name} has no value in .env.example");
    }
    expect(array_key_exists('NOTIFY_ARRAY_ENABLED', $values))->toBeTrue()
        ->and(in_array(strtolower($values['NOTIFY_ARRAY_ENABLED']), ['', 'false', '0'], true))->toBeTrue('the CI driver is off in .env.example');
})->group('invariants', 'module:notifications');

it('keeps every key and token out of .env.ci, which switches on only the array driver', function () {
    $values = envValues(dirname(__DIR__, 2).'/.env.ci');

    foreach (SECRET_SETTINGS as $name) {
        expect($values[$name] ?? '')->toBe('', "{$name} is empty or absent in .env.ci");
    }
    expect($values['NOTIFY_ARRAY_ENABLED'] ?? '')->toBe('true');
})->group('invariants', 'module:notifications');

it('holds no PEM block, bot token or VAPID key anywhere under apps/ or docs/', function () {
    $files = committedTextFiles();
    expect($files)->not->toBeEmpty();

    $offenders = [];
    foreach ($files as $file) {
        $findings = secretsIn((string) file_get_contents($file));
        if ($findings !== []) {
            $offenders[] = substr($file, strlen(repoRoot()) + 1).': '.implode(', ', $findings);
        }
    }

    expect($offenders)->toBe([]);
})->group('invariants', 'module:notifications');

it('recognises the shapes it guards against', function () {
    $token = random_int(100000000, 999999999).':'.Str::random(35);
    $publicKey = rtrim(strtr(base64_encode(random_bytes(65)), '+/', '-_'), '=');
    $privateKey = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    expect(strlen($publicKey))->toBe(87)->and(strlen($privateKey))->toBe(43)
        ->and(secretsIn("TELEGRAM_BOT_TOKEN={$token}"))->toBe(['a Telegram bot token'])
        ->and(secretsIn("VAPID_PRIVATE_KEY={$privateKey}"))->toBe(['a VAPID key'])
        ->and(secretsIn("'vapid_public_key' => '{$publicKey}'"))->toBe(['a VAPID key'])
        ->and(secretsIn(str_repeat('-', 5).'BEGIN EC PRIVATE KEY'.str_repeat('-', 5)))->toBe(['a PEM block'])
        ->and(secretsIn("VAPID_PRIVATE_KEY=<printed by the command>\nTELEGRAM_BOT_TOKEN=<from BotFather>"))->toBe([])
        ->and(secretsIn('a 43 character hash '.$privateKey.' that has nothing to do with it'))->toBe([]);
})->group('invariants', 'module:notifications');

it('names every setting in the runbook and gives none of them a value', function () {
    $runbook = (string) file_get_contents(repoRoot().'/docs/runbook/server.md');

    foreach (SECRET_SETTINGS as $name) {
        expect($runbook)->toContain($name);
    }
    foreach (['VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY', 'TELEGRAM_BOT_TOKEN', 'TELEGRAM_WEBHOOK_SECRET'] as $name) {
        preg_match_all('/^\s*'.$name.'=(.*)$/m', $runbook, $values);
        foreach ($values[1] as $value) {
            expect(trim($value) === '' || str_starts_with(trim($value), '<'))->toBeTrue("{$name} in the runbook has a placeholder, not a value");
        }
    }
    expect($runbook)->toContain('notifications:vapid-keys')->toContain('notifications:telegram-webhook')->toContain('notifications:status');
})->group('invariants', 'module:notifications');
