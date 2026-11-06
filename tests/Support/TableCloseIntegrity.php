<?php

namespace Tests\Support;

final class TableCloseIntegrity
{
    public static function isConsistent(array $state): bool
    {
        $closed = $state['status'] === 'closed';
        if (! $closed && $state['status'] !== 'open') {
            return false;
        }
        $count = $closed ? 1 : 0;

        return ($closed
            ? $state['sale_ids'] === [$state['linked_sale_id']]
            : $state['sale_ids'] === [] && $state['linked_sale_id'] === null)
            && $state['completed_sales'] === $count
            && $state['payments'] === $count
            && $state['sale_items'] === $count
            && $state['movements'] === $count
            && bccomp((string) $state['sale_total'], $closed ? '10' : '0', 2) === 0
            && bccomp((string) $state['payment_total'], $closed ? '10' : '0', 2) === 0
            && bccomp((string) $state['stock_after'], bcsub((string) $state['stock_before'], (string) $count, 3), 3) === 0
            && bccomp((string) $state['movement_quantity'], $closed ? '-1' : '0', 3) === 0;
    }
}
