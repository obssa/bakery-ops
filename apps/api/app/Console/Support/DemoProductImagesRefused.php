<?php

declare(strict_types=1);

namespace App\Console\Support;

use App\Shared\Models\Branch;
use RuntimeException;

/**
 * bakery:demo-product-images (and Phase6Seeder) wrote nothing: the manifest or one of its photos is not fit to attach, the branch is
 * not a demo branch, or there is no admin to name in the audit log. Every message says what to change; none holds a server path.
 */
final class DemoProductImagesRefused extends RuntimeException
{
    public static function manifest(string $why): self
    {
        return new self("manifest.json: {$why}. Nothing was written.");
    }

    public static function entry(string $label, string $why): self
    {
        return new self("manifest.json, {$label}: {$why}. Nothing was written.");
    }

    public static function branch(string $why): self
    {
        return new self("--branch {$why}. Nothing was written.");
    }

    public static function notADemoBranch(Branch $branch): self
    {
        return new self("--branch {$branch->id}: \"{$branch->name}\" is not a demo branch; demo photos go only into a demo branch (bakery:seed-demo makes one). Nothing was written.");
    }

    public static function actor(string $given, Branch $branch): self
    {
        return new self("--as {$given}: not an active user of \"{$branch->name}\" who may manage the catalog (catalog.manage). Nothing was written.");
    }

    public static function noAdmin(Branch $branch): self
    {
        return new self("\"{$branch->name}\" has no active admin to name in the audit log: activate one, or pass --as=<user id> of a user who may manage the catalog. Nothing was written.");
    }
}
