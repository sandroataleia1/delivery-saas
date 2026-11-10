<?php

namespace App\Actions;

use App\Models\DiningTable;
use App\Models\MenuOrderReceipt;

class PlaceMenuOrder
{
    public const GUEST = 'Cliente (QR Code)';

    public function __construct(private ReceiveMenuOrder $receive) {}

    public function handle(DiningTable $table, array $data): MenuOrderReceipt
    {
        return $this->receive->handle($table, $data);
    }
}
