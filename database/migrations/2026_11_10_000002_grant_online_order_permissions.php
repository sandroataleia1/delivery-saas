<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Quem já confirma pedidos públicos (tables.confirm) passa a aceitar pedidos online. */
return new class extends Migration
{
    private const PERMISSION = 'online_orders.accept';

    public function up(): void
    {
        foreach (DB::table('roles')->where('is_admin', false)->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?? [];

            if (in_array('tables.confirm', $permissions, true)) {
                $permissions = array_values(array_unique([...$permissions, self::PERMISSION]));
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->where('is_admin', false)->get() as $role) {
            $permissions = array_values(array_diff(json_decode($role->permissions, true) ?? [], [self::PERMISSION]));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
