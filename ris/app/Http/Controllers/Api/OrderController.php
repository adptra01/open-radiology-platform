<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderPriority;
use App\Enums\OrderStatus;
use App\Http\Controllers\Api\Concerns\AuthorizesPermissions;
use App\Models\AuditLog;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Order radiologi — worklist & CRUD untuk SPA.
 *
 * GET    /api/orders                     daftar (filter status/prioritas/tanggal/search)
 * GET    /api/orders/awaiting-report     order COMPLETED tanpa report (worklist radiolog)
 * POST   /api/orders                     buat order (order_number & accession otomatis)
 * GET    /api/orders/{order}             detail lengkap (study, report, appointment, AI)
 * PUT    /api/orders/{order}             ubah (prioritas, modalitas, dokter, catatan)
 * POST   /api/orders/{order}/cancel      batalkan order
 */
class OrderController extends Controller
{
    use AuthorizesPermissions;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'orders.view');

        $orders = Order::query()
            ->with(['patient:id,name,mrn', 'procedure:id,name,modality', 'modality:id,name', 'referringDoctor:id,name'])
            ->withCount(['studies', 'reports'])
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('priority')->toString(), fn ($q, $p) => $q->where('priority', $p))
            ->when($request->integer('modality_id'), fn ($q, $id) => $q->where('modality_id', $id))
            ->when($request->date('date'), fn ($q, $d) => $q->whereDate('requested_at', $d))
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where(fn ($qq) => $qq
                ->where('order_number', 'like', "%{$s}%")
                ->orWhere('accession_number', 'like', "%{$s}%")
                ->orWhereHas('patient', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('mrn', 'like', "%{$s}%"))))
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        return response()->json($orders);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'orders.create');

        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'procedure_id' => ['required', 'exists:procedures,id'],
            'modality_id' => ['nullable', 'exists:modalities,id'],
            'referring_doctor_id' => ['nullable', 'exists:doctors,id'],
            'priority' => ['nullable', Rule::enum(OrderPriority::class)],
            'scheduled_at' => ['nullable', 'date'],
            'status_note' => ['nullable', 'string'],
            'facility_id' => ['nullable', 'string', 'max:64'],
        ]);

        $order = Order::create($data);
        AuditLog::record('order.created', $order, array_keys($data));

        return response()->json($order->load(['patient', 'procedure', 'modality']), 201);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizePermission($request, 'orders.view');

        $order->load([
            'patient',
            'procedure',
            'modality',
            'referringDoctor',
            'appointments.modality',
            'studies.reports.radiologist',
            'studies.aiResults',
            'studies.transmissions.pacsSource',
            'reports.radiologist',
        ]);

        return response()->json($order);
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $this->authorizePermission($request, 'orders.edit');

        if ($order->status === OrderStatus::Cancelled) {
            return response()->json(['message' => 'Order sudah dibatalkan — tidak bisa diubah.'], 422);
        }

        $data = $request->validate([
            'modality_id' => ['nullable', 'exists:modalities,id'],
            'referring_doctor_id' => ['nullable', 'exists:doctors,id'],
            'priority' => ['nullable', Rule::enum(OrderPriority::class)],
            'scheduled_at' => ['nullable', 'date'],
            'status_note' => ['nullable', 'string'],
        ]);

        $order->update(array_filter($data, fn ($v) => $v !== null));
        AuditLog::record('order.updated', $order, array_keys($data));

        return response()->json($order->load(['patient', 'procedure', 'modality']));
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->authorizePermission($request, 'orders.cancel');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Completed], true)) {
            return response()->json([
                'message' => "Order berstatus {$order->status->value} tidak bisa dibatalkan.",
            ], 422);
        }

        $order->transitionTo(OrderStatus::Cancelled, $data['note'] ?? null);

        return response()->json($order->fresh()->load(['patient', 'procedure']));
    }

    public function awaitingReport(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'orders.view');

        // Menunggu laporan = order Completed yang BELUM punya report FINAL.
        // DRAFT/CANCELLED tidak dihitung selesai (M12.1).
        $hasFinalReport = fn ($q) => $q->where('status', \App\Enums\ReportStatus::Final->value);

        $orders = Order::query()
            ->with(['patient', 'procedure'])
            ->where('status', OrderStatus::Completed->value)
            ->whereDoesntHave('reports', $hasFinalReport)
            ->orderByDesc('completed_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $orders]);
    }
}
