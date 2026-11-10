<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TableResource;
use App\Support\TableFloor;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TableController extends Controller
{
    /** Salão do app: as mesmas mesas e comandas da tela Mesas. */
    public function index(): AnonymousResourceCollection
    {
        return TableResource::collection(TableFloor::rows());
    }
}
