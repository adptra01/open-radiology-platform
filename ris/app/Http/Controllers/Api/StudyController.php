<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPermissions;
use App\Models\Study;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Study terimpan di RIS (hasil C-STORE adapter) + data untuk viewer.
 *
 * GET /api/studies          daftar study (filter: search, modality, tanggal, order_id)
 * GET /api/studies/{study}  detail + report, AI result, transmisi, tautan OHIF
 */
class StudyController extends Controller
{
    use AuthorizesPermissions;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'orders.view');

        $studies = Study::query()
            ->with(['order.patient', 'order.procedure', 'order.modality'])
            ->withCount(['reports', 'transmissions', 'aiResults'])
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where(fn ($qq) => $qq
                ->where('accession_number', 'like', "%{$s}%")
                ->orWhere('study_instance_uid', 'like', "%{$s}%")
                ->orWhereHas('order.patient', fn ($p) => $p->where('name', 'like', "%{$s}%"))))
            ->when($request->string('modality')->toString(), fn ($q, $m) => $q->where('modality', $m))
            ->when($request->string('from')->toString(), fn ($q, $d) => $q->where('study_date', '>=', $d))
            ->when($request->string('to')->toString(), fn ($q, $d) => $q->where('study_date', '<=', $d))
            ->when($request->integer('order_id'), fn ($q, $id) => $q->where('order_id', $id))
            ->when($request->boolean('unmatched'), fn ($q) => $q->where('matched', false))
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        return response()->json($studies);
    }

    public function show(Request $request, Study $study): JsonResponse
    {
        $this->authorizePermission($request, 'orders.view');

        $study->load([
            'order.patient',
            'order.procedure',
            'order.modality',
            'reports.radiologist',
            'aiResults',
            'transmissions.pacsSource',
        ]);

        return response()->json([
            'study' => $study,
            // URL viewer dibentuk frontend dari prop `ohif_url` (config ris.viewer.ohif_url).
        ]);
    }
}
