<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\ProductionSector;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Cria a empresa com o setor de produção padrão, os perfis padrão e o primeiro administrador. */
class CreateCompany
{
    public function handle(array $company, array $admin, bool $mustChangePassword = true): Company
    {
        return DB::transaction(function () use ($company, $admin, $mustChangePassword) {
            $created = new Company($company);
            $created->active = true;
            $created->save();

            // Sem setor padrão, nenhum pedido chega à produção: a empresa já nasce com a Cozinha.
            $kitchen = new ProductionSector(['name' => 'Cozinha', 'active' => true]);
            $kitchen->company_id = $created->id;
            $kitchen->save();
            $created->forceFill(['default_production_sector_id' => $kitchen->id])->saveQuietly();

            foreach (config('permissions.default_roles') as $data) {
                $role = new Role([
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'permissions' => $data['permissions'],
                ]);
                $role->company_id = $created->id;
                $role->is_admin = $data['is_admin'];
                $role->save();

                if ($role->is_admin) {
                    $adminRole = $role;
                }
            }

            $user = new User($admin);
            $user->company_id = $created->id;
            $user->role_id = $adminRole->id;
            $user->must_change_password = $mustChangePassword;
            $user->save();

            return $created;
        });
    }
}
