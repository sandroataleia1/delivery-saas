<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            // Preenchido só enquanto aberto: o índice único garante um caixa por operador.
            $table->unsignedBigInteger('open_user_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('user_name');
            $table->decimal('opening_amount', 12, 2);
            $table->string('status', 20)->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by')->nullable();
            // Congelados no fechamento: cancelamentos posteriores não mudam o que foi conferido.
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('difference', 12, 2)->nullable();
            $table->string('closing_notes', 255)->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'open_user_id']);
            $table->index(['company_id', 'opened_at']);
            $table->index(['company_id', 'closed_at']);
            $table->index(['company_id', 'user_id', 'opened_at']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 12, 2);
            $table->string('reason', 255);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'type', 'created_at']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('cash_session_id')->nullable()->after('client_uuid')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_session_id');
        });

        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_sessions');
    }
};
