<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

#[Signature('app:create-admin {email} {--name=Admin} {--password=} {--super : Create a super admin}')]
#[Description('Create the first (or another) platform admin. Run once per environment after migrating.')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (User::withTrashed()->where('email', $email)->exists()) {
            $this->error("A user with email {$email} already exists.");

            return self::FAILURE;
        }

        $password = $this->option('password') ?: $this->secret('Password');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['email'], 'password' => [Password::defaults()]],
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $role = $this->option('super') ? 'super_admin' : 'admin';

        try {
            // All or nothing: a missing role must not leave a user behind who can never be an admin.
            DB::transaction(function () use ($email, $password, $role) {
                $user = User::create(['name' => $this->option('name'), 'email' => $email, 'password' => $password]);
                $user->forceFill(['email_verified_at' => now()])->save();
                $user->assignRole($role);
            });
        } catch (RoleDoesNotExist) {
            $this->error('The roles are missing. Run: php artisan db:seed --class=RoleSeeder');

            return self::FAILURE;
        }

        $this->info("Created {$email} as {$role}.");

        return self::SUCCESS;
    }
}
