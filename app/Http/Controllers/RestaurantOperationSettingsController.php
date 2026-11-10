<?php

namespace App\Http\Controllers;

use App\Actions\SaveRestaurantOperationSettings;
use App\Http\Requests\RestaurantOperationSettingsRequest;
use App\Models\Company;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestaurantOperationSettingsController extends Controller
{
    public function update(RestaurantOperationSettingsRequest $request, SaveRestaurantOperationSettings $save): Response
    {
        $save->handle($request->user()->company, $request->validated());

        return $request->expectsJson() ? response()->json(['saved' => true]) : back()->with('success', 'Operação atualizada.');
    }

    public function block(Request $request, Company $company, SaveRestaurantOperationSettings $save): Response
    {
        abort_unless($request->user()->is_super_admin, 403);
        $data = $request->validate(['blocked' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:255']]);
        $save->handle($company, ['automatic_blocked' => $data['blocked'], 'automatic_block_reason' => $data['reason'],
            'automatic_blocked_by_id' => $request->user()->id, 'automatic_blocked_at' => now()]);

        return $request->expectsJson() ? response()->json(['saved' => true]) : back()->with('success', 'Bloqueio atualizado.');
    }
}
