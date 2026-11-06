<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['zip_code', 'street', 'number', 'complement', 'district', 'city', 'state', 'reference'])]
class CustomerAddress extends Model
{
    use BelongsToCompany;
}
