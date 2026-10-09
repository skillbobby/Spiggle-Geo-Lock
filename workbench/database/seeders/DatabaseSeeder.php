<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Workbench\App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $editor = Role::findOrCreate('editor', 'web');
        $moderator = Role::findOrCreate('moderator', 'web');

        $user = User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Geo Lock Admin', 'password' => bcrypt('password')],
        );

        $user->syncRoles([$superAdmin, $editor, $moderator]);
    }
}
