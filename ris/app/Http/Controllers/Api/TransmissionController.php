<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransmissionStatus;
use App\Http\Controllers\Api\Concerns\AuthorizesPermissions;
use App\Jobs\ProcessTransmission;
use App\Models\AuditLog;
use App\Models\Transmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Antrean transmisi — lihat status kiriman ke PACS (M4).
 */
class TransmissionController extends Controller
{
    use AuthorizesPermissions;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'transmission.view');

        $transmissions = Transmission::query()
            ->with(['study.order.patient', 'pacsSource'])
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('search')->toString(), fn ($q, $s) => $q
                ->where(fn ($qq) => $qq
                    ->whereHas('study.order.patient', fn ($p) => $p->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('study', fn ($st) => $st->where('accession_number', 'like', "%{$s}%"))))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json($transmissions);
    }

    public function show(Request $request, Transmission $transmission): JsonResponse
    {
        $this->authorizePermission($request, 'transmission.view');

        $transmission->load(['study.order.patient', 'pacsSource']);

        return response()->json($transmission);
    }

    /**
     * Kirim ulang transmisi FAILED/CANCELLED (permission `transmission.retry`).
     */
    public function retry(Request $request, Transmission $transmission): JsonResponse
    {
        $this->authorizePermission($request, 'transmission.retry');

        if (! in_array($transmission->status, [TransmissionStatus::Failed, TransmissionStatus::Cancelled], true)) {
            return response()->json([
                'message' => "Hanya transmisi FAILED/CANCELLED yang bisa dikirim ulang (status saat ini: {$transmission->status->value}).",
            ], 422);
        }

        $previous = $transmission->status->value;

        $transmission->update([
            'status' => TransmissionStatus::Pending,
            'attempts' => 0,
            'error' => null,
            'next_attempt_at' => null,
        ]);

        AuditLog::record('transmission.retried', $transmission, ['previous_status' => $previous]);

        ProcessTransmission::dispatch($transmission);

        return response()->json($transmission->fresh()->load(['study.order.patient', 'pacsSource']));
    }
}