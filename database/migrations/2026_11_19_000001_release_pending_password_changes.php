<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A troca de senha no primeiro acesso deixou de ser obrigatória para quem é
 * cadastrado em Usuários: libera também quem já estava esperando para trocar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('must_change_password', true)->update(['must_change_password' => false]);
    }

    /** Não dá para saber quem estava com senha provisória antes; nada a desfazer. */
    public function down(): void {}
};
