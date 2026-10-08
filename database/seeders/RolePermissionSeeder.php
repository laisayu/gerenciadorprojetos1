<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public const MEMBER_PERMISSIONS = [
        'view_any_project', 'view_project', 'view_any_task', 'view_task',
        'update_own_task_status', 'comment_task',
    ];

    public const MANAGER_PERMISSIONS = [
        'view_any_project', 'view_project', 'create_project', 'update_project',
        'delete_project', 'manage_project_members', 'view_any_task', 'view_task',
        'create_task', 'update_task', 'delete_task', 'comment_task',
    ];

    public const ADMIN_PERMISSIONS = [
        'view_any_user', 'view_user', 'create_user', 'update_user', 'delete_user',
        'manage_roles', 'manage_permissions',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = array_unique([...self::MEMBER_PERMISSIONS, ...self::MANAGER_PERMISSIONS, ...self::ADMIN_PERMISSIONS]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate('admin', 'web')->syncPermissions(Permission::where('guard_name', 'web')->get());
        Role::findOrCreate('manager', 'web')->syncPermissions(self::MANAGER_PERMISSIONS);
        Role::findOrCreate('member', 'web')->syncPermissions(self::MEMBER_PERMISSIONS);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
