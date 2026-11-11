<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\WaiterNoticeResource;
use App\Models\WaiterNotice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoticeController extends Controller
{
    /** Os 50 avisos mais novos do garçom logado, com quantos ainda não foram lidos. */
    public function index(Request $request): JsonResponse
    {
        $notices = WaiterNotice::where('user_id', $request->user()->id)->orderByDesc('id')->limit(50)->get();

        return response()->json([
            'unread' => $this->unread($request),
            'data' => WaiterNoticeResource::collection($notices)->resolve($request),
        ]);
    }

    /** Marca como lidos os avisos do garçom até o id informado (o maior que a tela mostrou). */
    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['up_to_id' => ['required', 'integer', 'min:1']]);

        WaiterNotice::where('user_id', $request->user()->id)->where('id', '<=', $data['up_to_id'])->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['unread' => $this->unread($request)]);
    }

    private function unread(Request $request): int
    {
        return WaiterNotice::where('user_id', $request->user()->id)->whereNull('read_at')->count();
    }
}
