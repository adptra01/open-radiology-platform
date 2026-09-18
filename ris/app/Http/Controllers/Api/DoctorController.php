<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPermissions;
use App\Models\AuditLog;
use App\Models\Doctor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Master data dokter (perujuk / radiolog).
 * GET|POST /api/doctors, GET|PUT|DELETE /api/doctors/{doctor}
 */
class DoctorController extends Controller
{
    use AuthorizesPermissions;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'referring-doctors.view');

        $doctors = Doctor::query()
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', "%{$s}%")
                ->orWhere('specialty', 'like', "%{$s}%")))
            ->when($request->has('is_radiologist'), fn ($q) => $q->where('is_radiologist', $request->boolean('is_radiologist')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return response()->json($doctors);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'referring-doctors.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'specialty' => ['nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_radiologist' => ['nullable', 'boolean'],
        ]);

        $doctor = Doctor::create($data);
        AuditLog::record('doctor.created', $doctor, array_keys($data));

        return response()->json($doctor, 201);
    }

    public function show(Request $request, Doctor $doctor): JsonResponse
    {
        $this->authorizePermission($request, 'referring-doctors.view');

        return response()->json($doctor);
    }

    public function update(Request $request, Doctor $doctor): JsonResponse
    {
        $this->authorizePermission($request, 'referring-doctors.edit');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'specialty' => ['nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_radiologist' => ['nullable', 'boolean'],
        ]);

        $doctor->update($data);
        AuditLog::record('doctor.updated', $doctor, array_keys($data));

        return response()->json($doctor);
    }

    public function destroy(Request $request, Doctor $doctor): JsonResponse
    {
        $this->authorizePermission($request, 'referring-doctors.edit');

        $doctor->delete();
        AuditLog::record('doctor.deleted', $doctor);

        return response()->json(['ok' => true]);
    }
}
