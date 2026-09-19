<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReportStatus;
use App\Http\Controllers\Api\Concerns\AuthorizesPermissions;
use App\Models\Order;
use App\Models\Report;
use App\Models\Study;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\AuditLog;
use Illuminate\Routing\Controller;

/**
 * Reporting — CRUD report + transisi status DRAFT → DICTATED → VERIFIED → FINAL.
 * Endpoint web (bukan DICOM adapter): dilindungi auth + RBAC backend (M12.1):
 *   GET → reports.view | POST → reports.create | PUT → reports.edit
 *   transition → reports.sign | DELETE → reports.edit | amendments → reports.sign
 * FINAL immutable: edit/delete/overwrite ditolak; koreksi via amendment record baru.
 */
class ReportController extends Controller
{
    use AuthorizesPermissions;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'reports.view');

        $reports = Report::query()
            ->with(['order.patient', 'order.procedure', 'radiologist'])
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->whereHas('order.patient', fn ($p) => $p->where('name', 'like', "%{$s}%")))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json($reports);
    }

    public function show(Request $request, Report $report): JsonResponse
    {
        $this->authorizePermission($request, 'reports.view');

        $report->load(['order.patient', 'order.procedure', 'order.modality', 'study', 'radiologist', 'amendments']);

        return response()->json($report);
    }

    /**
     * Buat report untuk order. Bila order sudah punya study terakhir,
     * report otomatis tertaut ke study tersebut.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'reports.create');

        $data = $request->validate([
            'order_id' => ['required', 'exists:orders,id'],
            'findings' => ['nullable', 'string'],
            'impression' => ['nullable', 'string'],
        ]);

        $order = Order::findOrFail($data['order_id']);
        $study = $order->studies()->latest()->first();

        $report = Report::create([
            'order_id' => $order->id,
            'study_id' => $study?->id,
            'radiologist_id' => $request->user()?->id,
            'findings' => $data['findings'] ?? null,
            'impression' => $data['impression'] ?? null,
        ]);

        return response()->json($report->load('order.patient'), 201);
    }

    public function update(Request $request, Report $report): JsonResponse
    {
        $this->authorizePermission($request, 'reports.edit');

        if (! $report->status->isEditable()) {
            return response()->json(['message' => 'Report sudah final/verified, tidak bisa diedit.'], 422);
        }

        $data = $request->validate([
            'findings' => ['nullable', 'string'],
            'impression' => ['nullable', 'string'],
            'addendum' => ['nullable', 'string'],
        ]);

        $report->update(array_filter($data));
        AuditLog::record('report.updated', $report, array_keys(array_filter($data)));

        return response()->json($report);
    }

    /**
     * Transisi status: POST { "target": "DICTATED" | "VERIFIED" | "FINAL" | "CANCELLED" }
     */
    public function transition(Request $request, Report $report): JsonResponse
    {
        $this->authorizePermission($request, 'reports.sign');

        $data = $request->validate([
            'target' => ['required', 'string'],
        ]);

        $target = ReportStatus::tryFrom(strtoupper($data['target']));
        if ($target === null) {
            return response()->json(['message' => 'Status target tidak dikenal.'], 422);
        }

        if (! $report->transitionTo($target)) {
            return response()->json([
                'message' => "Transisi {$report->status->value} → {$target->value} tidak diizinkan.",
            ], 422);
        }

        return response()->json($report);
    }

    public function destroy(Request $request, Report $report): JsonResponse
    {
        $this->authorizePermission($request, 'reports.edit');

        // FINAL immutable (M12.1): hapus ditolak — koreksi hanya via amendment.
        if ($report->isFinal()) {
            return response()->json(['message' => 'Report FINAL tidak bisa dihapus. Buat amendment bila perlu koreksi.'], 422);
        }

        $report->delete();
        AuditLog::record('report.deleted', $report);

        return response()->json(['ok' => true]);
    }

    /**
     * Amendment post-FINAL (M12.1): record BARU yang mereferensikan report
     * FINAL via parent_report_id. Record FINAL tidak pernah diubah.
     * Amendment lahir DRAFT dan mengikuti lifecycle normal.
     * Permission: reports.sign (tindakan senior, setara verifikasi).
     */
    public function amend(Request $request, Report $report): JsonResponse
    {
        $this->authorizePermission($request, 'reports.sign');

        if (! $report->isFinal()) {
            return response()->json(['message' => 'Hanya report FINAL yang bisa diberi amendment.'], 422);
        }

        $data = $request->validate([
            'addendum' => ['required', 'string', 'max:10000'],
        ]);

        $amendment = $report->addAmendment($data['addendum'], $request->user()?->id);
        AuditLog::record('report.amended', $amendment, [
            'parent_report_id' => $report->id,
            'parent_report_number' => $report->report_number,
        ]);

        return response()->json($amendment->load('parent'), 201);
    }
}