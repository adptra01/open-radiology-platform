<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPermissions;
use App\Models\Modality;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Master data modalitas DICOM (CR/CT/MR/…).
 * GET|POST /api/modalities, GET|PUT|DELETE /api/modalities/{modality}
 */
class ModalityController extends Controller
{
    use AuthorizesPermissions;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'modalities.view');

        $modalities = Modality::query()
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', "%{$s}%")
                ->orWhere('ae_title', 'like', "%{$s}%")))
            ->when($request->string('modality_type')->toString(), fn ($q, $t) => $q->where('modality_type', $t))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return response()->json($modalities);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'modalities.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ae_title' => ['required', 'string', 'max:16', 'unique:modalities,ae_title'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'modality_type' => ['nullable', 'string', 'max:16'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_online' => ['nullable', 'boolean'],
        ]);

        $modality = Modality::create($data);

        return response()->json($modality, 201);
    }

    public function show(Request $request, Modality $modality): JsonResponse
    {
        $this->authorizePermission($request, 'modalities.view');

        return response()->json($modality);
    }

    public function update(Request $request, Modality $modality): JsonResponse
    {
        $this->authorizePermission($request, 'modalities.edit');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'ae_title' => ['sometimes', 'required', 'string', 'max:16', Rule::unique('modalities', 'ae_title')->ignore($modality->id)],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'modality_type' => ['nullable', 'string', 'max:16'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_online' => ['nullable', 'boolean'],
        ]);

        $modality->update($data);

        return response()->json($modality);
    }

    public function destroy(Request $request, Modality $modality): JsonResponse
    {
        $this->authorizePermission($request, 'modalities.edit');

        if ($modality->orders()->exists()) {
            return response()->json([
                'message' => 'Modalitas masih dipakai order — nonaktifkan saja (is_online=false).',
            ], 422);
        }

        $modality->delete();

        return response()->json(['ok' => true]);
    }
}
