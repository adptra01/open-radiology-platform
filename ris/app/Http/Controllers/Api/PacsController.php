<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesPermissions;
use App\Models\AuditLog;
use App\Models\PacsSource;
use App\Services\PacsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Integrasi PACS (M5) — proxy DICOMweb QIDO/WADO untuk UI.
 * GET    /api/pacs                      → daftar PACS terdaftar (?ping=1 untuk cek koneksi)
 * POST   /api/pacs                      → tambah PACS
 * PUT    /api/pacs/{pacs}               → ubah PACS
 * DELETE /api/pacs/{pacs}               → nonaktifkan (soft delete)
 * GET    /api/pacs/{pacs}/ping          → cek konektivitas QIDO
 * GET    /api/pacs/{pacs}/studies       → QIDO-RS cari studi
 * GET    /api/pacs/{pacs}/studies/{uid}/series → QIDO-RS series
 * GET    /api/pacs/{pacs}/instances/.../rendered → WADO-RS preview JPEG
 */
class PacsController extends Controller
{
    use AuthorizesPermissions;

    public function index(Request $request, PacsClient $client): JsonResponse
    {
        $this->authorizePermission($request, 'pacs.view');

        $ping = $request->boolean('ping');

        $sources = PacsSource::withTrashed()
            ->orderBy('name')
            ->get()
            ->map(fn (PacsSource $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'ae_title' => $p->ae_title,
                'host' => $p->host,
                'port' => $p->port,
                'base_url' => $p->base_url,
                'qido_url' => $p->qido_url,
                'wado_url' => $p->wado_url,
                'stow_url' => $p->stow_url,
                'username' => $p->username,
                'has_credentials' => $p->hasCredentials(),
                'is_active' => $p->is_active,
                'deleted_at' => $p->deleted_at,
                'reachable' => ($ping && $p->is_active) ? $client->ping($p) : null,
            ]);

        return response()->json(['data' => $sources]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'pacs.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ae_title' => ['required', 'string', 'max:16'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'qido_url' => ['nullable', 'url', 'max:255'],
            'wado_url' => ['nullable', 'url', 'max:255'],
            'stow_url' => ['nullable', 'url', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $pacs = PacsSource::create($data);
        AuditLog::record('pacs.created', $pacs, array_keys($data));

        return response()->json($pacs->makeHidden('password'), 201);
    }

    public function update(Request $request, PacsSource $pacs): JsonResponse
    {
        $this->authorizePermission($request, 'pacs.edit');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'ae_title' => ['sometimes', 'required', 'string', 'max:16'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'qido_url' => ['nullable', 'url', 'max:255'],
            'wado_url' => ['nullable', 'url', 'max:255'],
            'stow_url' => ['nullable', 'url', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Password bersifat write-only: kosong = jangan menyentuh nilai lama.
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $pacs->update($data);
        AuditLog::record('pacs.updated', $pacs, array_keys($data));

        return response()->json($pacs->makeHidden('password'));
    }

    public function destroy(Request $request, PacsSource $pacs): JsonResponse
    {
        $this->authorizePermission($request, 'pacs.edit');

        if ($pacs->transmissions()->whereIn('status', ['PENDING', 'SENDING'])->exists()) {
            return response()->json([
                'message' => 'Masih ada transmisi berjalan ke PACS ini — coba lagi setelah selesai.',
            ], 422);
        }

        $pacs->update(['is_active' => false]);
        $pacs->delete();
        AuditLog::record('pacs.deleted', $pacs);

        return response()->json(['ok' => true]);
    }

    public function ping(Request $request, PacsClient $client, PacsSource $pacs): JsonResponse
    {
        $this->authorizePermission($request, 'pacs.view');

        return response()->json(['reachable' => $client->ping($pacs)]);
    }

    public function studies(Request $request, PacsClient $client, PacsSource $pacs): JsonResponse
    {
        $this->authorizePermission($request, 'pacs.view');

        $filter = array_filter([
            'StudyInstanceUID' => $request->string('study_uid')->toString() ?: null,
            'AccessionNumber' => $request->string('accession')->toString() ?: null,
            'PatientName' => $request->string('patient_name')->toString() ?: null,
            'Modality' => $request->string('modality')->toString() ?: null,
        ]);

        return response()->json(['data' => $client->qidoStudies($pacs, $filter)]);
    }

    public function series(PacsClient $client, PacsSource $pacs, string $studyUid): JsonResponse
    {
        return response()->json(['data' => $client->qidoSeries($pacs, $studyUid)]);
    }

    public function instances(PacsClient $client, PacsSource $pacs, string $studyUid, string $seriesUid): JsonResponse
    {
        return response()->json(['data' => $client->qidoInstances($pacs, $studyUid, $seriesUid)]);
    }

    public function rendered(PacsClient $client, PacsSource $pacs, string $studyUid, string $seriesUid, string $instanceUid)
    {
        $img = $client->wadoRendered($pacs, $studyUid, $seriesUid, $instanceUid);
        if ($img === null) {
            return response()->json(['message' => 'preview tidak tersedia'], 404);
        }

        // Proxy bytes agar UI tidak perlu akses PACS langsung (CORS aman).
        return response($img['content'], 200)
            ->header('Content-Type', $img['content_type'])
            ->header('Cache-Control', 'public, max-age=86400');
    }
}