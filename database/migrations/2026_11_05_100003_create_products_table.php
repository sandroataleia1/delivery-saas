<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('sku', 40)->nullable();
            $table->string('barcode', 20)->nullable();
            $table->string('unit', 5)->default('UN');
            $table->decimal('sale_price', 12, 2)->default(0);
            // Custo médio ponderado: quatro casas para não acumular erro de arredondamento.
            $table->decimal('cost_price', 12, 4)->default(0);
            $table->boolean('track_stock')->default(true);
            // Saldo atual. Só muda pela ação MoveStock, junto com uma movimentação.
            $table->decimal('stock_quantity', 12, 3)->default(0);
            $table->decimal('min_stock', 12, 3)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'sku']);
            $table->unique(['company_id', 'barcode']);
            $table->index(['company_id', 'active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
