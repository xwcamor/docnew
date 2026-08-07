<?php

namespace App\Services\SystemManagement;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates and links a "system user" for a tenant. The system user is the
 * Sanctum token holder for API access — it never logs in via web.
 *
 * Email convention:  api+{slug}@system.local  (unique, never delivered)
 * Role:              api  (invisible in lists, no permissions by itself)
 * Password:          random hash (no one ever uses it)
 */
class TenantSystemUserService
{
    public function ensureFor(Tenant $tenant): User
    {
        // Already linked? Return it.
        if ($tenant->system_user_id) {
            $existing = User::withoutGlobalScopes()->find($tenant->system_user_id);
            if ($existing) return $existing;
        }

        // Try to find a previously-orphaned api user for this tenant.
        $email = "api+{$tenant->slug}@system.local";
        $user  = User::withoutGlobalScopes()->where('email', $email)->first();

        if (! $user) {
            $user = User::withoutGlobalScopes()->create([
                'name'       => "API · {$tenant->name}",
                'email'      => $email,
                'password'   => Hash::make(Str::random(64)),
                'tenant_id'  => $tenant->id,
                'country_id' => 1,    // sensible default — the user never uses these
                'locale_id'  => 1,
                'is_active'  => true,
                'created_by' => null,
            ]);
        }

        $user->syncRoles(['api']);

        // Link tenant ↔ system user (avoid recursion via raw query — observers won't fire).
        \DB::table('tenants')->where('id', $tenant->id)->update(['system_user_id' => $user->id]);
        $tenant->setAttribute('system_user_id', $user->id);

        return $user;
    }
}
