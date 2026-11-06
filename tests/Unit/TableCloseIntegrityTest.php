<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TableCloseIntegrity;

class TableCloseIntegrityTest extends TestCase
{
    private function closed(): array
    {
        return ['status' => 'closed', 'linked_sale_id' => 12, 'sale_ids' => [12], 'completed_sales' => 1,
            'sale_total' => '10.00', 'payment_total' => '10.00', 'payments' => 1, 'sale_items' => 1,
            'stock_before' => '50.000', 'stock_after' => '49.000', 'movement_quantity' => '-1.000', 'movements' => 1];
    }

    public static function inconsistentEffects(): array
    {
        return [
            'orphan sale' => [['sale_ids' => [12, 13]]],
            'wrong link' => [['linked_sale_id' => 13]],
            'missing payment' => [['payment_total' => '0.00']],
            'duplicate payment' => [['payments' => 2]],
            'double stock deduction' => [['stock_after' => '48.000']],
            'missing movement' => [['movements' => 0]],
            'wrong movement' => [['movement_quantity' => '-2.000']],
            'missing item' => [['sale_items' => 0]],
            'cancelled sale' => [['completed_sales' => 0]],
        ];
    }

    #[DataProvider('inconsistentEffects')]
    public function test_rejects_inconsistent_financial_or_stock_effects(array $changes): void
    {
        $this->assertFalse(TableCloseIntegrity::isConsistent(array_replace($this->closed(), $changes)));
    }

    public function test_accepts_one_complete_sale_and_one_stock_deduction(): void
    {
        $this->assertTrue(TableCloseIntegrity::isConsistent($this->closed()));
    }

    public function test_open_order_requires_no_sale_payment_or_stock_effect(): void
    {
        $state = array_replace($this->closed(), ['status' => 'open', 'linked_sale_id' => null, 'sale_ids' => [],
            'completed_sales' => 0, 'sale_total' => '0', 'payment_total' => '0', 'payments' => 0,
            'sale_items' => 0, 'stock_after' => '50.000', 'movement_quantity' => '0', 'movements' => 0]);
        $this->assertTrue(TableCloseIntegrity::isConsistent($state));
        $state['stock_after'] = '49.000';
        $this->assertFalse(TableCloseIntegrity::isConsistent($state));
    }
}
