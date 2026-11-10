<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Impressão térmica: impressoras da loja, fila de impressão lida pelo agente local,
 * a impressora de cada setor (cozinha, bar) e a impressora dos pedidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('connection', 10);
            $table->string('address');
            $table->unsignedSmallInteger('port')->nullable();
            $table->unsignedTinyInteger('columns')->default(48);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('printer_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->longText('payload');
            $table->string('status', 10)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        Schema::table('production_sectors', function (Blueprint $table) {
            $table->foreignId('printer_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('order_printer_id')->nullable()->constrained('printers')->nullOnDelete();
            $table->string('print_agent_token', 64)->nullable()->unique();
            $table->timestamp('print_agent_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_printer_id');
            $table->dropUnique(['print_agent_token']);
            $table->dropColumn(['print_agent_token', 'print_agent_seen_at']);
        });

        Schema::table('production_sectors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('printer_id');
        });

        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('printers');
    }
};
