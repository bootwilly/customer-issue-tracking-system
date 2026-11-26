<?php

namespace Database\Seeders;

use App\Models\FieldEditRule;
use App\Models\LookupItem;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkflowStatus;
use App\Models\WorkflowTransition;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $d = require __DIR__.'/data/workflow.php';

        foreach ($d['statuses'] as [$scope, $code, $name, $stage, $tab, $term, $excl, $rel, $sort]) {
            WorkflowStatus::updateOrCreate(['scope' => $scope, 'code' => $code], [
                'name' => $name, 'stage' => $stage, 'client_tab' => $tab, 'is_terminal' => $term,
                'is_excluded' => $excl, 'is_released' => $rel, 'sort' => $sort,
            ]);
        }
        foreach ($d['transitions'] as [$scope, $from, $to, $perm, $req]) {
            WorkflowTransition::updateOrCreate(['scope' => $scope, 'from_code' => $from, 'to_code' => $to],
                ['permission' => $perm, 'requires_fields' => $req]);
        }
        FieldEditRule::query()->delete();
        foreach ($d['field_rules'] as [$origin, $field, $stage, $perm, $actor]) {
            FieldEditRule::create(['origin' => $origin, 'field' => $field, 'stage' => $stage, 'permission' => $perm, 'actor_rule' => $actor]);
        }
        // admin-editable categories: seed only missing rows, never overwrite names/order changed in the UI
        $editable = collect($d['lookups'])->where(0, 'lookup_editable')->pluck(1)->all();
        foreach ($d['lookups'] as [$cat, $code, $name, $sort]) {
            in_array($cat, $editable, true)
                ? LookupItem::firstOrCreate(['category' => $cat, 'code' => $code], ['name' => $name, 'sort' => $sort])
                : LookupItem::updateOrCreate(['category' => $cat, 'code' => $code], ['name' => $name, 'sort' => $sort]);
        }
        foreach ($d['system_settings'] as [$key, $value]) {
            SystemSetting::firstOrCreate(['key' => $key], ['value' => $value]);
        }

        // Roles are global (project_id NULL); assignment to users is per project (team).
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        foreach ($d['roles'] as $code => [$type, $name, $perms]) {
            foreach ($perms as $p) {
                Permission::findOrCreate($p, 'web');
            }
            Role::findOrCreate($code, 'web')->syncPermissions($perms);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $pwd = (string) env('ADMIN_INITIAL_PASSWORD', '');
        if ($pwd !== '' && ! User::where('account', 'admin')->exists()) {
            User::create([
                'account' => 'admin', 'name' => 'Administrator',
                'email' => (string) env('ADMIN_EMAIL', 'admin@cits.local'),
                'password' => $pwd, 'user_type' => 'internal', 'is_admin' => true, 'status' => 'active',
            ]);
        }
    }
}
