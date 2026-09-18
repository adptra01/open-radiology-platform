<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPermissions;
use App\Models\Procedure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Katalog prosedur radiologi — read-only untuk UI (dipakai dropdown order).
 * GET /api/procedures, GET /api/procedures/{procedure}
 */
class ProcedureController extends Controller
{
    use AuthorizesPermissions;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'orders.view');

        $procedures = Procedure::query()
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")))
            ->when($request->string('modality')->toString(), fn ($q, $m) => $q->where('modality', $m))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return response()->json($procedures);
    }

    public function show(Request $request, Procedure $procedure): JsonResponse
    {
        $this->authorizePermission($request, 'orders.view');

        return response()->json($procedure);
    }
}
