<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Impressoras do Windows que o agente encontrou no computador da loja, para escolher no cadastro
     * em vez de digitar o nome. O pedido de busca fica marcado até o agente responder.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('print_agent_printers')->nullable()->after('print_agent_seen_at');
            $table->timestamp('print_agent_printers_at')->nullable()->after('print_agent_printers');
            $table->timestamp('print_agent_printers_requested_at')->nullable()->after('print_agent_printers_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['print_agent_printers', 'print_agent_printers_at', 'print_agent_printers_requested_at']);
        });
    }
};
