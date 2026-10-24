<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public const ROLES = ['customer', 'provider_owner', 'admin', 'super_admin'];

    public function run(): void
    {
        foreach (self::ROLES as $name) {
            Role::findOrCreate($name, 'api');
        }
    }
}
