<?php

namespace Database\Seeders;

use App\Support\Roles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The three roles the control plane recognises.
 *
 * Deliberately coarse. Authorization here is about blast radius, not about
 * fine-grained capability:
 *
 *   viewer    read dashboards, projects and run logs
 *   operator  everything a viewer can do, plus change what runs and trigger it
 *   admin     everything, plus destructive and irreversible actions
 *
 * Anything that deletes, discards data, or bypasses the normal path belongs
 * to admin. That currently means deleting projects and pipelines; it will
 * grow to cover emergency releases, adopting drift, and restoring backups.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Roles::ALL as $role) {
            Role::findOrCreate($role, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
