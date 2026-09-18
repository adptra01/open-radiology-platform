<?php

namespace App\Http\Controllers\Api;

use App\Jobs\RunAiInference;
use App\Models\AiResult;
use App\Models\Study;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Hasil inferensi AI (M6):
 *   GET  /api/ai-results              → daftar hasil (+ filter status/search study)
 *   GET  /api/ai-results/{result}     → detail satu hasil
 *   POST /api/ai-results/run/{study}  → (re-)dispatch inferensi manual untuk study
 */
class AiResultsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $results = AiResult::query()
            ->with(['study.order.patient'])
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('study_id')->toString(), fn ($q, $s) => $q->where('study_id', $s))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json($results);
    }

    public function show(AiResult $aiResult): JsonResponse
    {
        $aiResult->load(['study.order.patient']);

        return response()->json($aiResult);
    }

    /** Dispatch (ulang) inferensi untuk study tertentu. */
    public function run(Request $request, Study $study): JsonResponse
    {
        if (! $study->file_path) {
            return response()->json(['message' => 'Study tidak punya file DICOM.'], 422);
        }

        $aiResult = $study->aiResults()->create([
            'order_id' => $study->order_id,
            'status' => 'PENDING',
            'model_name' => 'densenet121-res224-all',
        ]);

        RunAiInference::dispatch($aiResult);

        return response()->json($aiResult, 202);
    }
}