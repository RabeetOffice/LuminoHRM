<?php

namespace Database\Seeders;

use App\Support\AccessSync;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        (new AccessSync)->run();
    }
}
