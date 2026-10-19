<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Models\User;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Tenancy\CurrentBranch;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * `bakery:create-admin {phone}`: the console way to the first admin of a branch, where no admin exists yet to use
 * POST /users (a fresh install, CI's e2e job, the isolated "E2E Test Branch" the live Playwright tests sign in to).
 *
 * The branch is found by name and created when no branch has that name (default: the oldest branch, or a new "Main"
 * on an empty install); the role matrix is seeded when the admin role is missing. It never changes an existing user:
 * a phone already in the branch is an error, not a password reset. The password is printed only when it was
 * generated. The creation is audited in the branch with the new admin as the actor (there is no one else yet).
 */
final class CreateAdminCommand extends Command
{
    /** @var string */
    protected $signature = 'bakery:create-admin
        {phone : the login phone number (9 to 15 digits, optional leading +)}
        {--name=Administrator : the admin\'s name}
        {--branch= : branch name; created when no branch has it (default: the oldest branch, or "Main" on an empty install)}
        {--password= : at least 8 characters; when omitted a password is generated and printed once}';

    /** @var string */
    protected $description = 'Create an admin user in a branch (creating the branch when it does not exist yet)';

    public function handle(ConnectionResolverInterface $db, ActorContext $actor, CurrentBranch $current): int
    {
        $phone = trim((string) $this->argument('phone'));
        $name = trim((string) $this->option('name'));
        $branchName = $this->option('branch');
        $branchName = is_string($branchName) && trim($branchName) !== '' ? trim($branchName) : null;
        $given = $this->option('password');
        $password = is_string($given) && $given !== '' ? $given : null;

        $validator = Validator::make(
            ['phone' => $phone, 'name' => $name, 'branch' => $branchName, 'password' => $password],
            [
                'phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9]{9,15}$/'],
                'name' => ['required', 'string', 'max:120'],
                'branch' => ['nullable', 'string', 'max:120'],
                'password' => ['nullable', 'string', 'min:8', 'max:255'],
            ],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $generated = $password === null;
        $password ??= Str::password(20, symbols: false);

        try {
            /** @var array{0: User, 1: Branch} $created */
            $created = $db->connection()->transaction(function () use ($phone, $name, $branchName, $password, $actor, $current): array {
                $this->ensureRoles();
                $branch = $this->branch($branchName);

                $taken = User::query()->withoutGlobalScope(BranchScope::class)->withTrashed()
                    ->where('branch_id', $branch->id)->where('phone', $phone)->exists();
                if ($taken) {
                    throw new RuntimeException("A user with phone {$phone} already exists in branch \"{$branch->name}\"; nothing was changed.");
                }

                $user = new User;
                $user->fill(['name' => $name, 'phone' => $phone, 'password' => $password, 'locale' => 'en', 'is_active' => true]);
                $user->branch_id = $branch->id;
                $user->save();
                $user->assignRole('admin');

                $current->set($branch);
                $actor->authenticate($user->id);
                app(AuditedAction::class)->recordEvent('user.create_admin', $user->getMorphClass(), $user->id, [
                    'phone' => $phone,
                    'branch' => $branch->name,
                    'source' => 'console',
                ]);

                return [$user, $branch];
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            // console processes are long-lived in tests and queue workers: leave no actor or branch behind
            $actor->reset();
            $current->clear();
        }

        [$user, $branch] = $created;
        $this->info("Admin {$user->phone} (user #{$user->id}) created in branch \"{$branch->name}\" (#{$branch->id}).");
        if ($generated) {
            $this->line("Password: {$password}");
            $this->line('It is shown only this once; store it now.');
        }

        return self::SUCCESS;
    }

    /** The role matrix (RolesSeeder, idempotent) when it has never been seeded; an existing matrix is left as it is. */
    private function ensureRoles(): void
    {
        if (! Role::query()->where('name', 'admin')->where('guard_name', RolesSeeder::GUARD)->exists()) {
            app(RolesSeeder::class)->run();
        }
    }

    private function branch(?string $name): Branch
    {
        if ($name !== null) {
            return Branch::query()->where('name', $name)->orderBy('id')->first()
                ?? Branch::query()->create(['name' => $name]);
        }

        return Branch::query()->orderBy('id')->first() ?? Branch::query()->create(['name' => 'Main']);
    }
}
