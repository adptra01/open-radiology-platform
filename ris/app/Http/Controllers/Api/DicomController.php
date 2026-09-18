<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Jobs\RunAiInference;
use App\Models\AuditLog;
use App\Models\MppsRecord;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Study;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint machine-to-machine untuk DICOM adapter Python.
 *
 * - GET  worklists  : MWL C-FIND — order aktif (REQUESTED/SCHEDULED/ARRIVED)
 * - POST mpps       : MPPS N-CREATE/N-SET/N-ACTION → alur status order
 * - POST studies    : C-STORE forward → match accession → order COMPLETED
 * - POST adapters/online : heartbeat adapter (dicatat ke audit log)
 */
class DicomController extends Controller
{
    public function worklist(Request $request): JsonResponse
    {
        $query = Order::query()
            ->with(['patient', 'procedure', 'modality', 'referringDoctor'])
            ->whereIn('status', [OrderStatus::Requested, OrderStatus::Scheduled, OrderStatus::Arrived]);

        // Filter DICOM (keyword C-FIND), semua opsional
        if ($request->filled('AccessionNumber')) {
            $query->where('accession_number', 'like', '%'.$request->string('AccessionNumber').'%');
        }
        if ($request->filled('PatientID')) {
            $query->whereHas('patient', fn ($q) => $q->where('mrn', $request->string('PatientID')));
        }
        if ($request->filled('PatientsName')) {
            $query->whereHas('patient', fn ($q) => $q->where('name', 'ilike', '%'.$request->string('PatientsName').'%'));
        }
        if ($request->filled('Modality')) {
            $query->whereHas('procedure', fn ($q) => $q->where('modality', $request->string('Modality')));
        }
        if ($request->filled('ScheduledStationAETitle')) {
            $query->whereHas('modality', fn ($q) => $q->where('ae_title', $request->string('ScheduledStationAETitle')));
        }
        if ($request->filled('ScheduledProcedureStepStartDate')) {
            $date = $request->string('ScheduledProcedureStepStartDate')->toString();
            $query->whereDate('scheduled_at', \DateTime::createFromFormat('Ymd', $date) ?: $date);
        }

        $items = $query->orderBy('scheduled_at')->limit(200)->get()->map(fn (Order $order) => $this->worklistRow($order));

        return response()->json(['items' => $items]);
    }

    public function mpps(Request $request): JsonResponse
    {
        $payload = $request->all();
        $type = strtoupper((string) ($payload['type'] ?? 'N-SET'));
        $sopUid = trim((string) ($payload['SOPInstanceUID'] ?? ''));
        $status = strtoupper((string) ($payload['PerformedProcedureStepStatus'] ?? ''));

        $accession = $this->extractAccession($payload);

        // N-SET/N-ACTION tidak wajib membawa AccessionNumber, sedangkan
        // SOPInstanceUID selalu ada. Resolusi dari tabel mpps_records
        // (diisi saat N-CREATE) → tahan restart adapter, multi-instance.
        $record = $sopUid !== '' ? MppsRecord::where('sop_instance_uid', $sopUid)->first() : null;
        if ($accession === null && $record !== null) {
            $accession = $record->resolvedAccession();
        }

        $order = $accession !== null ? Order::where('accession_number', $accession)->first() : null;

        // Segarkan pemetaan MPPS supaya request berikutnya (tanpa accession) tetap bisa
        // diresolusi. Baris hanya dibuat bila ada sesuatu untuk diingat (SOP dikenal
        // atau accession diketahui) — N-CREATE selalu memenuhi syarat.
        if ($sopUid !== '' && ($record !== null || $accession !== null)) {
            $record = $this->recordMpps($record, $sopUid, $accession, $order, $status, $payload);
        }

        if ($accession === null) {
            Log::info('MPPS received without resolvable accession', ['type' => $type, 'sop_instance_uid' => $sopUid]);
            AuditLog::record('dicom.mpps.unresolved', null, ['type' => $type, 'sop_instance_uid' => $sopUid]);

            return response()->json(['matched' => false, 'reason' => 'accession unresolved'], 200);
        }

        if ($order === null) {
            Log::info('MPPS received for unknown accession', ['accession' => $accession, 'type' => $type]);
            AuditLog::record('dicom.mpps.unknown', null, ['accession' => $accession, 'type' => $type]);

            return response()->json(['matched' => false, 'reason' => 'order not found'], 200);
        }

        $target = match (true) {
            str_contains($status, 'COMPLETED') => OrderStatus::Acquired,
            str_contains($status, 'DISCONTINUED'), $type === 'N-ACTION' => OrderStatus::Cancelled,
            str_contains($status, 'IN PROGRESS') => OrderStatus::InProgress,
            default => $type === 'N-CREATE' ? OrderStatus::InProgress : null,
        };

        $walked = true;
        if ($target !== null && $order->status !== $target) {
            $walked = $order->walkTo($target, "MPPS $type ($status)");
            Log::info('MPPS applied', ['accession' => $accession, 'type' => $type, 'target' => $target->value, 'walked' => $walked]);
        }

        AuditLog::record('dicom.mpps', $order, [
            'type' => $type,
            'performed_status' => $status,
            'walked' => $walked,
            'sop_instance_uid' => $sopUid !== '' ? $sopUid : null,
            'accession_source' => $this->extractAccession($payload) !== null ? 'payload' : 'mpps_record',
        ]);

        return response()->json([
            'matched' => true,
            'order_number' => $order->order_number,
            'accession_number' => $order->accession_number,
            'status' => $order->fresh()->status->value,
            'sop_instance_uid' => $sopUid !== '' ? $sopUid : null,
        ]);
    }

    /**
     * Upsert baris mpps_records: peta SOPInstanceUID → accession/order + status terakhir.
     */
    private function recordMpps(
        ?MppsRecord $record,
        string $sopUid,
        ?string $accession,
        ?Order $order,
        string $status,
        array $payload,
    ): MppsRecord {
        $record ??= new MppsRecord;
        $record->sop_instance_uid = $sopUid;

        if ($accession !== null) {
            $record->accession_number = $accession;
        }
        if ($order !== null) {
            $record->order_id = $order->id;
        }
        if ($status !== '') {
            $record->status = $status;
        }

        $modality = (string) ($payload['Modality'] ?? (($payload['PerformedSeriesSequence'][0]['Modality'] ?? '')));
        if ($modality !== '') {
            $record->modality = substr($modality, 0, 16);
        }

        if ($record->performed_started_at === null) {
            $record->performed_started_at = now();
        }
        if (str_contains($status, 'COMPLETED') || str_contains($status, 'DISCONTINUED')) {
            $record->performed_ended_at = now();
        }

        $record->last_seen_at = now();
        $record->save();

        return $record;
    }

    public function storeStudy(Request $request): JsonResponse
    {
        $payload = $request->all();
        $accession = (string) ($payload['accession_number'] ?? '');
        $sopUid = (string) ($payload['sop_instance_uid'] ?? '');

        if ($sopUid === '') {
            return response()->json(['message' => 'sop_instance_uid required'], 422);
        }

        $order = $accession !== '' ? Order::where('accession_number', $accession)->first() : null;

        $study = DB::transaction(function () use ($payload, $order, $sopUid) {
            $study = Study::updateOrCreate(
                ['sop_instance_uid' => $sopUid],
                [
                    'accession_number' => $payload['accession_number'] ?? null,
                    'study_instance_uid' => $payload['study_instance_uid'] ?? null,
                    'series_instance_uid' => $payload['series_instance_uid'] ?? null,
                    'sop_class_uid' => $payload['sop_class_uid'] ?? null,
                    'modality' => $payload['modality'] ?? null,
                    'study_description' => $payload['study_description'] ?? null,
                    'study_date' => $payload['study_date'] ?? null,
                    'study_time' => $payload['study_time'] ?? null,
                    'file_path' => $payload['file_path'] ?? null,
                    'patient_id' => $order?->patient_id,
                    'order_id' => $order?->id,
                    'matched' => $order !== null,
                    'matched_at' => $order !== null ? now() : null,
                ],
            );

            if ($order !== null) {
                $order->walkTo(OrderStatus::Completed, 'C-STORE received');
                Log::info('Study matched to order', ['accession' => $order->accession_number, 'sop' => $sopUid]);
            } else {
                Log::warning('Study unmatched (no order for accession)', ['accession' => $payload['accession_number'] ?? null, 'sop' => $sopUid]);
            }

            return $study;
        });

        AuditLog::record($order !== null ? 'dicom.study.matched' : 'dicom.study.unmatched', $study, [
            'accession' => $payload['accession_number'] ?? null,
            'order_id' => $order?->id,
            'sop_instance_uid' => $sopUid,
        ]);

        // M4: antrekan transmisi ke PACS eksternal (STOW-RS) bila ada PACS aktif.
        $transmission = $study->queueTransmission();
        if ($transmission !== null) {
            AuditLog::record('dicom.transmission.queued', $transmission, [
                'study_id' => $study->id,
                'pacs_source_id' => $transmission->pacs_source_id,
                'type' => $transmission->transmission_type,
            ]);
        }

        // M6: antrekan inferensi AI (CXR screening) — non-fatal bila worker mati.
        $aiResult = $study->aiResults()->create([
            'order_id' => $study->order_id,
            'status' => 'PENDING',
            'model_name' => 'densenet121-res224-all',
        ]);
        if (config('services.ai_worker.auto_infer', true)) {
            RunAiInference::dispatch($aiResult);
        }

        return response()->json([
            'matched' => $order !== null,
            'study_id' => $study->id,
            'order_number' => $order?->order_number,
            'order_status' => $order?->fresh()->status?->value,
            'transmission_id' => $transmission?->id,
            'ai_result_id' => $aiResult->id,
        ], $order !== null ? 200 : 201);
    }

    public function announceOnline(Request $request): JsonResponse
    {
        $payload = $request->only(['ae_title', 'host', 'mwl_port', 'mpps_port', 'store_port']);
        AuditLog::record('dicom.adapter.online', null, $payload);
        Log::info('DICOM adapter announced online', $payload);

        return response()->json(['ok' => true, 'received_at' => now()->toIso8601String()]);
    }

    private function extractAccession(array $payload): ?string
    {
        $value = (string) ($payload['AccessionNumber'] ?? '');
        if ($value !== '') {
            return $value;
        }

        // MPPS menaruh accession di dalam ScheduledStepAttributesSequence
        $sequence = $payload['ScheduledStepAttributesSequence'] ?? [];
        if (is_array($sequence)) {
            foreach ($sequence as $step) {
                if (isset($step['AccessionNumber']) && $step['AccessionNumber'] !== '') {
                    return (string) $step['AccessionNumber'];
                }
            }
        }

        return null;
    }

    /** Bentuk baris worklist yang dipetakan adapter ke dataset DICOM. */
    private function worklistRow(Order $order): array
    {
        $patient = $order->patient;
        $procedure = $order->procedure;
        $modality = $order->modality;

        return [
            'patient_id' => $patient?->mrn ?? '',
            'patient_name' => $patient?->name ?? '',
            'patient_birth_date' => $patient?->birth_date?->format('Ymd') ?? '',
            'patient_sex' => $this->patientSex($patient?->gender?->value),
            'accession_number' => $order->accession_number,
            'requested_procedure_id' => $order->order_number,
            'requested_procedure_description' => $procedure?->name ?? '',
            'scheduled_station_aetitle' => $modality?->ae_title ?? '',
            'scheduled_start_date' => $order->scheduled_at?->format('Ymd') ?? '',
            'scheduled_start_time' => $order->scheduled_at?->format('His') ?? '',
            'modality' => $procedure?->modality ?? '',
            'performing_physician_name' => $order->referringDoctor?->name ?? '',
            'procedure_step_description' => $procedure?->name ?? '',
        ];
    }

    private function patientSex(?string $gender): string
    {
        return match ($gender) {
            'male' => 'M',
            'female' => 'F',
            default => 'O',
        };
    }
}