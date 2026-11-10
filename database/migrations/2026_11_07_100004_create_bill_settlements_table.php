<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->boolean('legacy_review_required')->default(false);
        });
        Schema::create('bill_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('bill_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('discount_reason', 255)->nullable();
            $table->date('paid_at');
            $table->string('payment_method', 20);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name');
            $table->string('notes', 255)->nullable();
            $table->foreignId('cash_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('legacy_imported')->default(false);
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by_id')->nullable();
            $table->string('reversed_by')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->foreignId('reversal_cash_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'paid_at']);
            $table->index(['company_id', 'reversed_at']);
            $table->index(['bill_id', 'reversed_at']);
        });
        $this->importLegacySettlements();
    }

    public function importLegacySettlements(): void
    {
        DB::table('bills')->orderBy('id')->chunkById(200, function ($bills) {
            foreach ($bills as $bill) {
                if (DB::table('bill_settlements')->where('bill_id', $bill->id)->exists()) {
                    continue;
                }
                if ($bill->status !== 'paid') {
                    if (bccomp((string) ($bill->paid_amount ?? '0'), '0', 2) !== 0) {
                        DB::table('bills')->where('id', $bill->id)->update(['legacy_review_required' => true]);
                    }

                    continue;
                }
                $coherent = $bill->paid_amount !== null && bccomp((string) $bill->paid_amount, (string) $bill->amount, 2) === 0
                    && bccomp((string) $bill->paid_amount, '0', 2) > 0
                    && $bill->paid_at && $bill->paid_at !== '0000-00-00'
                    && in_array($bill->payment_method, ['cash', 'pix', 'transfer', 'boleto', 'credit', 'debit'], true)
                    && ($bill->payment_method === 'cash'
                        ? $bill->cash_movement_id !== null && DB::table('cash_movements')
                            ->where('id', $bill->cash_movement_id)->where('company_id', $bill->company_id)
                            ->where('amount', $bill->paid_amount)
                            ->where('type', $bill->type === 'payable' ? 'bill_payment' : 'bill_receipt')->exists()
                        : $bill->cash_movement_id === null);
                if (! $coherent) {
                    DB::table('bills')->where('id', $bill->id)->update(['legacy_review_required' => true]);

                    continue;
                }
                DB::table('bill_settlements')->insert([
                    'company_id' => $bill->company_id, 'bill_id' => $bill->id,
                    'amount' => $bill->paid_amount, 'discount_amount' => '0.00',
                    'paid_at' => $bill->paid_at, 'payment_method' => $bill->payment_method,
                    'user_id' => null, 'user_name' => $bill->settled_by ?? 'Não informado (legado)',
                    'notes' => $bill->notes, 'cash_movement_id' => $bill->cash_movement_id,
                    'legacy_imported' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('bill_settlements')->exists()) {
            throw new RuntimeException('Rollback recusado: existe histórico financeiro. Use correção aditiva e backup validado.');
        }
        Schema::dropIfExists('bill_settlements');
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn(['discount_amount', 'legacy_review_required']);
        });
    }
};
