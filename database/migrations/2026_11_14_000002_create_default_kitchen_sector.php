<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Empresas criadas antes do setor padrão automático não tinham para onde mandar os pedidos:
 * quem não tem nenhum setor ganha a Cozinha como padrão. Quem já configurou setores fica como está.
 */
return new class extends Migration
{
    public function up(): void
    {
        $companies = DB::table('companies')
            ->whereNull('default_production_sector_id')
            ->whereNotExists(fn ($query) => $query->from('production_sectors')->whereColumn('production_sectors.company_id', 'companies.id'))
            ->pluck('id');

        foreach ($companies as $companyId) {
            $sectorId = DB::table('production_sectors')->insertGetId([
                'company_id' => $companyId,
                'name' => 'Cozinha',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('companies')->where('id', $companyId)->update(['default_production_sector_id' => $sectorId]);
        }
    }

    public function down(): void
    {
        // Não desfaz: o setor pode já ter recebido pedidos.
    }
};
