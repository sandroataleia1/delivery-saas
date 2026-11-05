<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('description', 150);
            $table->string('category', 40);
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('counterparty', 120)->nullable();
            $table->foreignId('stock_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('due_date');
            $table->string('status', 20)->default('open');
            $table->uuid('installment_group')->nullable();
            $table->unsignedSmallInteger('installment_number')->default(1);
            $table->unsignedSmallInteger('installment_total')->default(1);
            $table->date('paid_at')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('cash_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('settled_by')->nullable();
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'type', 'status', 'due_date']);
            $table->index(['company_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
