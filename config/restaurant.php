<?php

return [
    'defaults' => [
        'confirmation_mode' => 'manual', 'max_lines' => 20, 'max_line_units' => 10,
        'max_order_units' => 50, 'max_order_value' => '500.00', 'max_pending_orders' => 5,
        'max_pending_units' => 100, 'max_pending_value' => '1000.00',
        'visual_alerts' => true, 'sound_alerts' => true, 'late_after_minutes' => 10,
        'waiter_notices' => 'all',
    ],
    'ceilings' => ['max_lines' => 30, 'max_line_units' => 50, 'max_order_units' => 200,
        'max_order_value' => 5000, 'max_pending_orders' => 20, 'max_pending_units' => 500,
        'max_pending_value' => 10000, 'late_after_minutes' => 120],
];
