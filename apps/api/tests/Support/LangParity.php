<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Compares two Laravel language folders (step 5.4a, LangParityTest): the same *.php files, the same keys at every depth, the same shape
 * (a text in one is a text in the other, a group a group), no empty value, no __TODO__ in the second, and the same :placeholders in
 * each pair of texts. Returns one line per gap, an empty list when the folders agree.
 */
final class LangParity
{
    private const string PLACEHOLDER = '/:([A-Za-z_][A-Za-z0-9_]*)/';

    /**
     * @return list<string>
     */
    public static function compare(string $enDir, string $amDir): array
    {
        $en = self::files($enDir);
        $am = self::files($amDir);
        $problems = [];
        foreach (array_diff($en, $am) as $file) {
            $problems[] = "{$file}: missing from lang/am";
        }
        foreach (array_diff($am, $en) as $file) {
            $problems[] = "{$file}: in lang/am but not in lang/en";
        }
        foreach (array_intersect($en, $am) as $file) {
            $problems = [...$problems, ...self::tree(
                self::load("{$enDir}/{$file}"),
                self::load("{$amDir}/{$file}"),
                substr($file, 0, -4),
            )];
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function files(string $dir): array
    {
        $files = array_map('basename', glob("{$dir}/*.php") ?: []);
        sort($files);

        return $files;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function load(string $path): array
    {
        $tree = require $path;

        return is_array($tree) ? $tree : [];
    }

    /**
     * @param  array<array-key, mixed>  $en
     * @param  array<array-key, mixed>  $am
     * @return list<string>
     */
    private static function tree(array $en, array $am, string $prefix): array
    {
        $problems = [];
        foreach ($en as $key => $enValue) {
            $path = "{$prefix}.{$key}";
            if (! array_key_exists($key, $am)) {
                $problems[] = "{$path}: missing from am";

                continue;
            }
            $amValue = $am[$key];
            if (is_array($enValue) && is_array($amValue)) {
                $problems = [...$problems, ...self::tree($enValue, $amValue, $path)];

                continue;
            }
            if (is_array($enValue) || is_array($amValue)) {
                $problems[] = is_array($enValue) ? "{$path}: a group in en, a text in am" : "{$path}: a text in en, a group in am";

                continue;
            }
            $problems = [...$problems, ...self::pair($enValue, $amValue, $path)];
        }
        foreach (array_keys(array_diff_key($am, $en)) as $key) {
            $problems[] = "{$prefix}.{$key}: in am but not in en";
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function pair(mixed $en, mixed $am, string $path): array
    {
        if (! is_string($en) || trim($en) === '') {
            return ["{$path}: empty in en"];
        }
        if (! is_string($am) || trim($am) === '') {
            return ["{$path}: empty in am"];
        }
        if (str_contains($en, '__TODO__')) {
            return ["{$path}: __TODO__ in en"];
        }
        if (str_contains($am, '__TODO__')) {
            return ["{$path}: __TODO__ in am"];
        }
        $enNames = self::placeholders($en);
        $amNames = self::placeholders($am);
        if ($enNames !== $amNames) {
            $list = static fn (array $names): string => $names === [] ? 'none' : implode(' ', array_map(fn (string $n): string => ":{$n}", $names));

            return ["{$path}: placeholders differ (en {$list($enNames)}, am {$list($amNames)})"];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
