<?php

namespace App\Http\Controllers\Api;

use App\Jobs\RunAiRun;
use App\Models\AiRun;
use App\Models\Study;
use App\Services\AiClient;
use App\Services\IdentifierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * AI Gateway API generik (keputusan arsitektur final M10+):
 *   GET  /api/ai/capabilities        → daftar task dari gateway (proxy)
 *   GET  /api/ai/runs?study_id=&task_id=&status= → riwayat runs
 *   POST /api/ai/runs                → buat run queued + dispatch (202)
 *   GET  /api/ai/runs/{runId}        → detail satu run (run_id manusiawi)
 *
 * Laravel = system of record (run/audit); Python = inference engine.
 * Trigger manual — tidak ada auto-run baru di sini.
 */
class AiRunsController extends Controller
{
    public function capabilities(AiClient $client): JsonResponse
    {
        $caps = $client->capabilities();

        if (! $caps['ok']) {
            return response()->json(['message' => 'AI Gateway tidak terjangkau.', 'error' => $caps['error']], 503);
        }

        return response()->json(['data' => $caps['tasks']]);
    }

    public function index(Request $request): JsonResponse
    {
        $runs = AiRun::query()
            ->with(['study.order.patient'])
            ->when($request->string('study_id')->toString(), fn ($q, $s) => $q->where('study_id', $s))
            ->when($request->string('task_id')->toString(), fn ($q, $s) => $q->where('task_id', $s))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json($runs);
    }

    public function store(Request $request, AiClient $client, IdentifierService $ids): JsonResponse
    {
        $data = $request->validate([
            'study_id' => ['required', 'integer', 'exists:studies,id'],
            'task_id' => ['required', 'string', 'max:64'],
            'series_id' => ['nullable', 'string', 'max:128'],
        ]);

        $study = Study::findOrFail($data['study_id']);
        if (! $study->file_path) {
            return response()->json(['message' => 'Study tidak punya file DICOM.'], 422);
        }

        // Task harus dikenal gateway (bukan hardcode di RIS).
        $caps = $client->capabilities();
        if (! $caps['ok']) {
            return response()->json(['message' => 'AI Gateway tidak terjangkau.', 'error' => $caps['error']], 503);
        }
        $known = collect($caps['tasks'])->firstWhere('task_id', $data['task_id']);
        if (! $known) {
            return response()->json(['message' => "Task AI '{$data['task_id']}' tidak dikenal. Lihat GET /api/ai/capabilities."], 422);
        }

        $run = AiRun::create([
            'run_id' => $ids->nextRunId(),
            'study_id' => $study->id,
            'series_id' => $data['series_id'] ?? $study->series_instance_uid,
            'task_id' => $data['task_id'],
            'model_id' => $known['model']['id'] ?? null,
            'model_version' => $known['model']['version'] ?? null,
            'status' => AiRun::STATUS_QUEUED,
            'input_reference' => [
                'study_uid' => $study->study_instance_uid,
                'series_uid' => $study->series_instance_uid,
                'source' => 'study.file_path',
            ],
            'created_by' => $request->user()?->id,
        ]);

        RunAiRun::dispatch($run);

        return response()->json($run, 202);
    }

    public function show(string $runId): JsonResponse
    {
        $run = AiRun::with(['study.order.patient'])->where('run_id', $runId)->firstOrFail();

        return response()->json($run);
    }
}
