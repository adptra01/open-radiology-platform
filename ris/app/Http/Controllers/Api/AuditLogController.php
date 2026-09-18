<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPermissions;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Audit trail — read-only untuk UI (permission `audit.view`).
 * GET /api/audit-logs?action=&user_id=&from=&to=&search=
 */
class AuditLogController extends Controller
{
    use AuthorizesPermissions;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'audit.view');

        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->when($request->string('action')->toString(), fn ($q, $a) => $q->where('action', 'like', "%{$a}%"))
            ->when($request->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->date('from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->date('to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where(fn ($qq) => $qq
                ->where('action', 'like', "%{$s}%")
                ->orWhere('auditable_type', 'like', "%{$s}%")
                ->orWhere('auditable_id', 'like', "%{$s}%")))
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return response()->json($logs);
    }
}
