<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 30);
            // Com sinal: positivo aumenta o saldo, negativo reduz.
            $table->decimal('quantity', 12, 3);
            $table->decimal('balance_after', 12, 3);
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->default('Sistema');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'product_id', 'created_at']);
            $table->index(['company_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
