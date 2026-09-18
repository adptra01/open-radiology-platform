<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Enums\ReportStatus;
use App\Http\Controllers\Api\Concerns\AuthorizesPermissions;
use App\Models\AiResult;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Report;
use App\Models\Study;
use App\Models\Transmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Ringkasan dashboard. Bagian yang dikirim hanya yang boleh dilihat user
 * (dicek per permission) supaya role seperti Auditor tetap dapat halaman
 * tanpa error 403.
 *
 * GET /api/dashboard
 */
class DashboardController extends Controller
{
    use AuthorizesPermissions;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $sections = [];

        if ($user->can('orders.view')) {
            $sections['workflow'] = [
                'orders_by_status' => Order::query()
                    ->selectRaw('status, count(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status'),
                'awaiting_report' => Order::query()
                    ->where('status', OrderStatus::Completed->value)
                    ->whereDoesntHave('reports', fn ($q) => $q->whereIn('status', array_column(ReportStatus::cases(), 'value')))
                    ->count(),
                'studies_today' => Study::query()->whereDate('study_date', today())->count(),
                'unmatched_studies' => Study::query()->where('matched', false)->count(),
                'recent_orders' => Order::query()
                    ->with(['patient:id,name,mrn', 'procedure:id,name,modality'])
                    ->latest('id')
                    ->limit(8)
                    ->get(['id', 'order_number', 'accession_number', 'patient_id', 'procedure_id', 'status', 'priority', 'requested_at']),
            ];
        }

        if ($user->can('scheduling.view')) {
            $sections['scheduling'] = [
                'appointments_today' => Appointment::query()
                    ->whereDate('scheduled_at', today())
                    ->selectRaw('status, count(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status'),
                'next_appointment' => Appointment::query()
                    ->with(['order.patient:id,name', 'modality:id,name'])
                    ->where('scheduled_at', '>=', now())
                    ->whereNotIn('status', ['COMPLETED', 'CANCELLED', 'NO_SHOW'])
                    ->orderBy('scheduled_at')
                    ->first(),
            ];
        }

        if ($user->can('reports.view')) {
            $sections['reports'] = [
                'reports_by_status' => Report::query()
                    ->selectRaw('status, count(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status'),
                'my_draft_reports' => Report::query()
                    ->where('radiologist_id', $user->id)
                    ->whereIn('status', [ReportStatus::Draft->value, ReportStatus::Dictated->value])
                    ->count(),
            ];
        }

        if ($user->can('transmission.view')) {
            $sections['pacs'] = [
                'transmissions_by_status' => Transmission::query()
                    ->selectRaw('status, count(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status'),
            ];
        }

        if ($user->can('patients.view')) {
            $sections['patients'] = [
                'total' => Patient::query()->count(),
                'registered_today' => Patient::query()->whereDate('created_at', today())->count(),
            ];
        }

        if ($user->can('orders.view')) {
            $sections['ai'] = [
                'results_by_status' => AiResult::query()
                    ->selectRaw('status, count(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status'),
            ];
        }

        return response()->json(['data' => $sections]);
    }
}
