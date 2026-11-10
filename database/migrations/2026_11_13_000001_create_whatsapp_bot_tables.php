<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['whatsapp_bot_messages', 'whatsapp_conversations', 'whatsapp_inbound_messages'];

    public function up(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable();
            $table->boolean('auto_reply')->default(false);
            $table->text('greeting')->nullable();
            $table->json('ignored_numbers')->nullable();
        });
        Schema::create('whatsapp_inbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('wa_message_id', 100);
            $table->string('remote_jid', 100);
            $table->boolean('from_me')->default(false);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['company_id', 'wa_message_id']);
            $table->index('created_at');
        });
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('remote_jid', 100);
            $table->string('phone', 20)->nullable();
            $table->string('contact_name', 80)->nullable();
            $table->string('state', 30)->default('idle');
            $table->json('context')->nullable();
            $table->uuid('cart_uuid')->nullable();
            $table->string('cart_hash', 64)->nullable();
            $table->unsignedTinyInteger('misunderstood')->default(0);
            $table->timestamp('last_customer_at')->nullable();
            $table->timestamp('last_store_at')->nullable();
            $table->timestamp('human_until')->nullable();
            $table->timestamp('waiting_since')->nullable();
            $table->timestamp('cart_touched_at')->nullable();
            $table->foreignId('last_online_order_id')->nullable()->constrained('online_orders')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'remote_jid']);
            $table->index(['company_id', 'waiting_since']);
            $table->index(['company_id', 'updated_at']);
        });
        Schema::create('whatsapp_bot_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->string('wa_message_id', 100)->nullable();
            $table->text('body');
            $table->timestamp('created_at')->nullable();
            $table->index(['company_id', 'wa_message_id']);
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Rollback recusado: existem dados do bot do WhatsApp. Use migração corretiva.');
            }
        }
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->dropColumn(['webhook_secret', 'auto_reply', 'greeting', 'ignored_numbers']);
        });
    }
};
