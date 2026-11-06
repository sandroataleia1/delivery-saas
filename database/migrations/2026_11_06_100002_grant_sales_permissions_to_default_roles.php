<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Empresas já existentes recebem nos perfis padrão as permissões de venda. */
return new class extends Migration
{
    private const GRANTS = [
        'Gerente' => ['sales.create', 'sales.view', 'sales.discount', 'sales.cancel'],
        'Caixa' => ['sales.create', 'sales.view'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $name => $grants) {
            foreach (DB::table('roles')->where('name', $name)->where('is_admin', false)->get() as $role) {
                $permissions = array_values(array_unique([...json_decode($role->permissions, true), ...$grants]));

                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::GRANTS as $name => $grants) {
            foreach (DB::table('roles')->where('name', $name)->where('is_admin', false)->get() as $role) {
                $permissions = array_values(array_diff(json_decode($role->permissions, true), $grants));

                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            }
        }
    }
};
