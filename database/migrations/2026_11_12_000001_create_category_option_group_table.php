<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Grupo de adicionais ligado a uma categoria vale para todos os produtos dela, inclusive os cadastrados depois. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_option_group', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_group_id')->constrained()->restrictOnDelete();
            $table->unique(['category_id', 'option_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_option_group');
    }
};
