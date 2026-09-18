<?php

namespace App\Http\Controllers\Api;

use App\Enums\Gender;
use App\Http\Controllers\Api\Concerns\AuthorizesPermissions;
use App\Models\AuditLog;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Master data pasien (SPA).
 *
 * GET    /api/patients              daftar (paginate + search)
 * POST   /api/patients              buat (MRN di-generate IdentifierService)
 * GET    /api/patients/{patient}    detail + order terakhir
 * PUT    /api/patients/{patient}    ubah
 * DELETE /api/patients/{patient}    soft delete      (patients.edit)
 * POST   /api/patients/{patient}/merge  gabung duplikat (patients.merge)
 */
class PatientController extends Controller
{
    use AuthorizesPermissions;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'patients.view');

        $patients = Patient::query()
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', "%{$s}%")
                ->orWhere('mrn', 'like', "%{$s}%")
                ->orWhere('identity_number', 'like', "%{$s}%")))
            ->withCount('orders')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return response()->json($patients);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'patients.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string'],
            'identity_type' => ['nullable', 'string', 'max:32'],
            'identity_number' => ['nullable', 'string', 'max:64'],
            'mrn' => ['nullable', 'string', 'max:32', 'unique:patients,mrn'],
        ]);

        $patient = Patient::create($data);
        AuditLog::record('patient.created', $patient, array_keys($data));

        return response()->json($patient, 201);
    }

    public function show(Request $request, Patient $patient): JsonResponse
    {
        $this->authorizePermission($request, 'patients.view');

        $patient->loadCount('orders');
        $patient->load(['orders' => fn ($q) => $q->with('procedure')->latest()->limit(10)]);

        return response()->json($patient);
    }

    public function update(Request $request, Patient $patient): JsonResponse
    {
        $this->authorizePermission($request, 'patients.edit');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string'],
            'identity_type' => ['nullable', 'string', 'max:32'],
            'identity_number' => ['nullable', 'string', 'max:64'],
        ]);

        $patient->update($data);
        AuditLog::record('patient.updated', $patient, array_keys($data));

        return response()->json($patient);
    }

    public function destroy(Request $request, Patient $patient): JsonResponse
    {
        $this->authorizePermission($request, 'patients.edit');

        if ($patient->orders()->exists()) {
            return response()->json([
                'message' => 'Pasien masih punya order — gunakan merge atau batalkan order dulu.',
            ], 422);
        }

        $patient->delete();
        AuditLog::record('patient.deleted', $patient);

        return response()->json(['ok' => true]);
    }

    /**
     * Gabung pasien duplikat: semua order dipindah ke `$patient` (target),
     * lalu sumber di-soft delete. Berguna untuk hasil registrasi ganda.
     *
     * Body: { "source_id": <id> }
     */
    public function merge(Request $request, Patient $patient): JsonResponse
    {
        $this->authorizePermission($request, 'patients.merge');

        $data = $request->validate([
            'source_id' => ['required', 'integer', Rule::exists('patients', 'id')->whereNull('deleted_at')],
        ]);

        $source = Patient::findOrFail($data['source_id']);

        if ($source->is($patient)) {
            return response()->json(['message' => 'Tidak bisa merge pasien dengan dirinya sendiri.'], 422);
        }

        $moved = $source->orders()->update(['patient_id' => $patient->id]);

        // Study ikut dipindah agar tidak menunjuk pasien yang sudah di-soft delete.
        \App\Models\Study::where('patient_id', $source->id)->update(['patient_id' => $patient->id]);

        $source->delete();
        AuditLog::record('patient.merged', $patient, [
            'source_id' => $source->id,
            'source_mrn' => $source->mrn,
            'orders_moved' => $moved,
        ]);

        return response()->json(['ok' => true, 'orders_moved' => $moved]);
    }
}
