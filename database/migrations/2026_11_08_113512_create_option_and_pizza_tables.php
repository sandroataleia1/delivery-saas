<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['option_recipe_items', 'option_prices', 'options', 'pizza_sizes', 'product_option_group', 'option_groups'];

    public function up(): void
    {
        Schema::create('option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->string('kind', 10)->default('extra');
            $table->unsignedTinyInteger('min_choices')->default(0);
            $table->unsignedTinyInteger('max_choices')->default(1);
            $table->boolean('allows_quantity')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('product_option_group', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('option_group_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unique(['product_id', 'option_group_id']);
        });
        Schema::create('options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('option_group_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->boolean('paused')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::create('pizza_sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('name', 40);
            $table->unsignedTinyInteger('slices')->nullable();
            $table->unsignedTinyInteger('max_flavors')->default(1);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('option_prices', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('option_id')->constrained()->restrictOnDelete();
            $table->foreignId('pizza_size_id')->constrained()->restrictOnDelete();
            $table->decimal('price', 12, 2);
            $table->unique(['option_id', 'pizza_size_id']);
        });
        Schema::create('option_recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('option_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('pizza_size_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('ingredient_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->timestamps();
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER option_recipe_scope_insert BEFORE INSERT ON option_recipe_items WHEN NEW.option_id IS NULL AND NEW.pizza_size_id IS NULL BEGIN SELECT RAISE(ABORT, 'Option or pizza size required'); END");
            DB::statement("CREATE TRIGGER option_recipe_scope_update BEFORE UPDATE ON option_recipe_items WHEN NEW.option_id IS NULL AND NEW.pizza_size_id IS NULL BEGIN SELECT RAISE(ABORT, 'Option or pizza size required'); END");
        } else {
            DB::statement('ALTER TABLE option_recipe_items ADD CONSTRAINT option_recipe_scope CHECK (option_id IS NOT NULL OR pizza_size_id IS NOT NULL)');
        }
        Schema::table('products', fn (Blueprint $table) => $table->string('pizza_pricing', 10)->nullable());
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Rollback recusado: existem opções ou pizzas cadastradas. Use migração corretiva.');
            }
        }
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('pizza_pricing'));
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
