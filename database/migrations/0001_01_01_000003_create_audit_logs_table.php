<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sem chaves estrangeiras de propósito: o histórico sobrevive a qualquer exclusão.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->default('Sistema');
            $table->string('event', 40);
            $table->string('auditable_type', 60)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('description');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('url', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'user_id', 'created_at']);
            $table->index(['company_id', 'event', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
        });

        // Imutabilidade no banco: vale até para SQL escrito à mão.
        if (in_array(DB::getDriverName(), ['mariadb', 'mysql'], true)) {
            foreach (['UPDATE' => 'alterada', 'DELETE' => 'excluída'] as $operation => $verb) {
                $name = 'audit_logs_block_'.strtolower($operation);

                DB::unprepared("
                    CREATE TRIGGER $name BEFORE $operation ON audit_logs FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A auditoria não pode ser $verb.'
                ");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
