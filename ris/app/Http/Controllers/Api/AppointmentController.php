<?php

namespace App\Http\Controllers\Api;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Api\Concerns\AuthorizesPermissions;
use App\Models\Appointment;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Penjadwalan (appointment) — order modalitas.
 *
 * GET    /api/appointments                  daftar (filter tanggal/status)
 * POST   /api/appointments                  buat slot
 * PUT    /api/appointments/{appointment}    ubah slot (scheduling.reschedule bila jadwal berubah)
 * DELETE /api/appointments/{appointment}    batalkan slot (soft delete)
 * POST   /api/appointments/{appointment}/transition  CONFIRMED / CHECKED_IN / COMPLETED / NO_SHOW / CANCELLED
 */
class AppointmentController extends Controller
{
    use AuthorizesPermissions;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'scheduling.view');

        $appointments = Appointment::query()
            ->with(['order.patient', 'order.procedure', 'modality'])
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->date('date'), fn ($q, $d) => $q->whereDate('scheduled_at', $d))
            ->when($request->string('search')->toString(), fn ($q, $s) => $q
                ->whereHas('order.patient', fn ($p) => $p->where('name', 'like', "%{$s}%")))
            ->orderBy('scheduled_at')
            ->paginate($request->integer('per_page', 15));

        return response()->json($appointments);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'scheduling.create');

        $data = $request->validate([
            'order_id' => ['required', 'exists:orders,id'],
            'modality_id' => ['nullable', 'exists:modalities,id'],
            'scheduled_at' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $appointment = Appointment::create($data);
        AuditLog::record('appointment.created', $appointment, array_keys($data));

        return response()->json($appointment->load(['order.patient', 'modality']), 201);
    }

    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorizePermission($request, 'scheduling.view');

        return response()->json($appointment->load(['order.patient', 'order.procedure', 'modality']));
    }

    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate([
            'scheduled_at' => ['nullable', 'date'],
            'modality_id' => ['nullable', 'exists:modalities,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $rescheduled = array_key_exists('scheduled_at', $data)
            && $data['scheduled_at'] !== null
            && $appointment->scheduled_at?->toIso8601String() !== \Illuminate\Support\Carbon::parse($data['scheduled_at'])->toIso8601String();

        $this->authorizePermission(
            $request,
            $rescheduled ? 'scheduling.reschedule' : 'scheduling.edit',
        );

        $appointment->update(array_filter($data, fn ($v) => $v !== null));
        AuditLog::record('appointment.updated', $appointment, array_keys($data));

        return response()->json($appointment->load(['order.patient', 'modality']));
    }

    public function destroy(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorizePermission($request, 'scheduling.edit');

        $appointment->delete();
        AuditLog::record('appointment.deleted', $appointment);

        return response()->json(['ok' => true]);
    }

    /**
     * Transisi status appointment + izin yang menyertainya.
     *
     * Body: { "target": "CONFIRMED"|"CHECKED_IN"|"COMPLETED"|"NO_SHOW"|"CANCELLED", "note": "..." }
     */
    public function transition(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate([
            'target' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $target = AppointmentStatus::tryFrom(strtoupper($data['target']));

        if ($target === null) {
            return response()->json(['message' => 'Status target tidak dikenal.'], 422);
        }

        $permission = match ($target) {
            AppointmentStatus::Confirmed => 'scheduling.confirm',
            AppointmentStatus::CheckedIn, AppointmentStatus::Completed, AppointmentStatus::NoShow => 'scheduling.check-in',
            AppointmentStatus::Cancelled => 'scheduling.edit',
            AppointmentStatus::Scheduled => 'scheduling.edit',
        };

        $this->authorizePermission($request, $permission);

        if (! self::canTransition($appointment->status, $target)) {
            return response()->json([
                'message' => "Transisi {$appointment->status->value} → {$target->value} tidak diizinkan.",
            ], 422);
        }

        $appointment->status = $target;
        if ($data['note'] ?? null) {
            $appointment->notes = trim(($appointment->notes ? $appointment->notes."\n" : '').$data['note']);
        }
        $appointment->save();

        AuditLog::record('appointment.status', $appointment, ['target' => $target->value, 'note' => $data['note'] ?? null]);

        return response()->json($appointment->load(['order.patient', 'modality']));
    }

    /**
     * Peta transisi yang diizinkan (mirror logika UI).
     */
    private static function canTransition(AppointmentStatus $from, AppointmentStatus $to): bool
    {
        if ($from === $to) {
            return false;
        }

        $allowed = [
            AppointmentStatus::Scheduled->value => [
                AppointmentStatus::Confirmed->value,
                AppointmentStatus::CheckedIn->value,
                AppointmentStatus::NoShow->value,
                AppointmentStatus::Cancelled->value,
            ],
            AppointmentStatus::Confirmed->value => [
                AppointmentStatus::CheckedIn->value,
                AppointmentStatus::NoShow->value,
                AppointmentStatus::Cancelled->value,
            ],
            AppointmentStatus::CheckedIn->value => [
                AppointmentStatus::Completed->value,
                AppointmentStatus::Cancelled->value,
            ],
        ];

        return in_array($to->value, $allowed[$from->value] ?? [], true);
    }
}
