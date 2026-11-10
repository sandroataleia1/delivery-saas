<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\OnlineStore;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WhatsAppAlertsTest extends TestCase
{
    use RefreshDatabase;

    private function orderFor(int $companyId, int $number, ?string $token = null): int
    {
        return DB::table('online_orders')->insertGetId([
            'company_id' => $companyId, 'number' => $number, 'client_uuid' => "uuid-$companyId-$number",
            'content_hash' => str_repeat('a', 64), 'tracking_token_hash' => hash('sha256', $token ?? "t-$companyId-$number"),
            'tracking_token' => 'x', 'status' => 'pending', 'type' => 'pickup', 'customer_name' => 'Joana',
            'customer_phone' => '11988887777', 'subtotal' => 10, 'total' => 10, 'payment_method' => 'pix',
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function failedMessage(int $companyId, int $number, $updatedAt): void
    {
        $message = WhatsAppMessage::factory()->create([
            'online_order_id' => $this->orderFor($companyId, $number),
            'company_id' => $companyId,
            'status' => WhatsAppMessage::FAILED,
        ]);
        DB::table('whatsapp_messages')->where('id', $message->id)->update(['updated_at' => $updatedAt]);
    }

    public function test_orders_page_flags_disconnected_whatsapp(): void
    {
        $admin = $this->adminOf();
        OnlineStore::factory()->create(['company_id' => $admin->company_id, 'enabled' => true]);

        $this->actingAs($admin)->get(route('online.orders'))->assertInertia(fn (Assert $page) => $page->where('whatsapp', null));

        $setting = WhatsAppSetting::factory()->create(['company_id' => $admin->company_id, 'enabled' => true, 'state' => 'close']);
        $this->get(route('online.orders'))->assertInertia(fn (Assert $page) => $page
            ->where('whatsapp.connected', false)->where('whatsapp.failed_recent', 0));

        $setting->forceFill(['state' => 'open'])->save();
        $this->get(route('online.orders'))->assertInertia(fn (Assert $page) => $page->where('whatsapp.connected', true));
    }

    public function test_orders_page_counts_recent_failures(): void
    {
        $admin = $this->adminOf();
        $other = Company::factory()->create();
        OnlineStore::factory()->create(['company_id' => $admin->company_id, 'enabled' => true]);
        WhatsAppSetting::factory()->create(['company_id' => $admin->company_id, 'enabled' => true]);

        $this->failedMessage($admin->company_id, 1, now());
        $this->failedMessage($admin->company_id, 2, now()->subHour());
        $this->failedMessage($admin->company_id, 3, now()->subDays(2));
        $this->failedMessage($other->id, 4, now());

        $this->actingAs($admin)->get(route('online.orders'))->assertInertia(fn (Assert $page) => $page->where('whatsapp.failed_recent', 2));
    }

    public function test_track_page_mentions_whatsapp_only_when_connected(): void
    {
        $company = Company::factory()->create();
        OnlineStore::factory()->create(['company_id' => $company->id, 'enabled' => true, 'slug' => 'lanchonete']);
        $token = str_repeat('a', 48);
        $this->orderFor($company->id, 1, $token);
        $url = route('shop.track', ['lanchonete', $token]);

        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('whatsapp_updates', false));

        $setting = WhatsAppSetting::factory()->create(['company_id' => $company->id, 'enabled' => true, 'state' => 'close']);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('whatsapp_updates', false));

        $setting->forceFill(['state' => 'open'])->save();
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('whatsapp_updates', true));
    }
}
