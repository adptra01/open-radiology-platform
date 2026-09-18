<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Enums\ReportStatus;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Report;
use App\Models\Study;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * FHIR R4 stub (M5) — expose domain RIS sebagai resource FHIR:
 * Patient, ServiceRequest, ImagingStudy, DiagnosticReport.
 *
 * Fokus interop (pembaca eksternal); bukan implementasi penuh.
 * Format: application/fhir+json; meta.profile diisi minimal.
 */
class FhirController extends Controller
{
    private const FHIR_VERSION = '4.0.1';

    /** CapabilityStatement minimal. */
    public function metadata(Request $request): Response
    {
        return $this->fhir([
            'resourceType' => 'CapabilityStatement',
            'status' => 'active',
            'date' => now()->toIso8601String(),
            'fhirVersion' => self::FHIR_VERSION,
            'kind' => 'instance',
            'rest' => [[
                'mode' => 'server',
                'resource' => [
                    ['type' => 'Patient', 'interaction' => [['code' => 'read'], ['code' => 'search-type']]],
                    ['type' => 'ServiceRequest', 'interaction' => [['code' => 'read'], ['code' => 'search-type']]],
                    ['type' => 'ImagingStudy', 'interaction' => [['code' => 'read'], ['code' => 'search-type']]],
                    ['type' => 'DiagnosticReport', 'interaction' => [['code' => 'read'], ['code' => 'search-type']]],
                ],
            ]],
        ], $request);
    }

    /** Search/read pasien. */
    public function patientSearch(Request $request): Response
    {
        $birthDate = $request->input('birthdate') ?: null;

        $query = Patient::query()
            ->when($request->input('_id'), fn ($q, $id) => $q->where('id', $id))
            ->when($request->input('name'), fn ($q, $name) => $q->where('name', 'like', "%{$name}%"))
            ->when($birthDate, fn ($q, $bd) => $q->whereDate('birth_date', $birthDate))
            ->limit(50);

        $bundle = [
            'resourceType' => 'Bundle',
            'type' => 'searchset',
            'total' => (clone $query)->count(),
            'entry' => $query->get()->map(fn (Patient $p) => [
                'resource' => $this->patientResource($p),
            ])->all(),
        ];

        return $this->fhir($bundle, $request);
    }

    public function patientRead(Request $request, Patient $patient): Response
    {
        return $this->fhir($this->patientResource($patient), $request);
    }

    /** ServiceRequest = Order. */
    public function serviceRequestSearch(Request $request): Response
    {
        $query = Order::query()
            ->with(['patient', 'procedure', 'modality'])
            ->when($request->input('_id'), fn ($q, $id) => $q->where('id', $id))
            ->when($request->input('accession'), fn ($q, $acc) => $q->where('accession_number', 'like', "%{$acc}%"))
            ->when($request->input('patient'), fn ($q, $pid) => $q->where('patient_id', $pid))
            ->limit(50);

        return $this->fhir([
            'resourceType' => 'Bundle',
            'type' => 'searchset',
            'total' => (clone $query)->count(),
            'entry' => $query->get()->map(fn (Order $o) => ['resource' => $this->serviceRequestResource($o)])->all(),
        ], $request);
    }

    public function serviceRequestRead(Request $request, Order $order): Response
    {
        $order->load(['patient', 'procedure', 'modality']);

        return $this->fhir($this->serviceRequestResource($order), $request);
    }

    /** ImagingStudy = Study. */
    public function imagingStudySearch(Request $request): Response
    {
        $query = Study::query()
            ->with(['patient', 'order'])
            ->when($request->input('_id'), fn ($q, $id) => $q->where('id', $id))
            ->when($request->input('accession'), fn ($q, $acc) => $q->where('accession_number', 'like', "%{$acc}%"))
            ->when($request->input('patient'), fn ($q, $pid) => $q->where('patient_id', $pid))
            ->limit(50);

        return $this->fhir([
            'resourceType' => 'Bundle',
            'type' => 'searchset',
            'total' => (clone $query)->count(),
            'entry' => $query->get()->map(fn (Study $s) => ['resource' => $this->imagingStudyResource($s)])->all(),
        ], $request);
    }

    public function imagingStudyRead(Request $request, Study $study): Response
    {
        $study->load(['patient', 'order']);

        return $this->fhir($this->imagingStudyResource($study), $request);
    }

    /** DiagnosticReport = Report. */
    public function diagnosticReportSearch(Request $request): Response
    {
        $query = Report::query()
            ->with(['order.patient', 'study', 'radiologist'])
            ->when($request->input('_id'), fn ($q, $id) => $q->where('id', $id))
            ->when($request->input('patient'), fn ($q, $pid) => $q->whereHas('order', fn ($o) => $o->where('patient_id', $pid)))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', strtoupper($s)))
            ->limit(50);

        return $this->fhir([
            'resourceType' => 'Bundle',
            'type' => 'searchset',
            'total' => (clone $query)->count(),
            'entry' => $query->get()->map(fn (Report $r) => ['resource' => $this->diagnosticReportResource($r)])->all(),
        ], $request);
    }

    public function diagnosticReportRead(Request $request, Report $report): Response
    {
        $report->load(['order.patient', 'study', 'radiologist']);

        return $this->fhir($this->diagnosticReportResource($report), $request);
    }

    /* ------------------------------------------------------------------ */

    private function fhir(array $payload, Request $request): Response
    {
        // Body string manual agar Symfony tidak meng-override Content-Type
        // menjadi application/json (prepare()).
        return response(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            200,
            [
                'Content-Type' => 'application/fhir+json; charset=UTF-8',
                'X-FHIR-Version' => self::FHIR_VERSION,
            ],
        );
    }

    private function patientResource(Patient $p): array
    {
        $nameParts = array_filter([$p->name]);
        $gender = match ($p->gender?->value) {
            'male' => 'male',
            'female' => 'female',
            default => 'unknown',
        };

        return [
            'resourceType' => 'Patient',
            'id' => (string) $p->id,
            'identifier' => [['system' => 'urn:oid:1.2.3.4.5.6.7.8.9.1', 'value' => $p->mrn]],
            'name' => $nameParts ? [['family' => $p->name, 'text' => $p->name]] : [],
            'gender' => $gender,
            'birthDate' => $p->birth_date?->format('Y-m-d'),
            'telecom' => $p->phone ? [['system' => 'phone', 'value' => $p->phone]] : [],
        ];
    }

    private function serviceRequestResource(Order $o): array
    {
        return [
            'resourceType' => 'ServiceRequest',
            'id' => (string) $o->id,
            'identifier' => [
                ['system' => 'urn:oid:1.2.3.4.5.6.7.8.9.2', 'value' => $o->accession_number],
                ['system' => 'urn:oid:1.2.3.4.5.6.7.8.9.3', 'value' => $o->order_number],
            ],
            'status' => match ($o->status) {
                OrderStatus::Cancelled => 'revoked',
                OrderStatus::Completed, OrderStatus::Acquired => 'completed',
                default => 'active',
            },
            'intent' => 'original-order',
            'subject' => ['reference' => "Patient/{$o->patient_id}"],
            'code' => $o->procedure ? [
                'coding' => [['code' => $o->procedure->code, 'display' => $o->procedure->name]],
            ] : null,
            'occurrenceDateTime' => $o->scheduled_at?->toIso8601String(),
            'authoredOn' => $o->requested_at?->toIso8601String(),
        ];
    }

    private function imagingStudyResource(Study $s): array
    {
        return [
            'resourceType' => 'ImagingStudy',
            'id' => (string) $s->id,
            'identifier' => [['system' => 'urn:ietf:rfc:3986', 'value' => "urn:oid:{$s->study_instance_uid}"]],
            'status' => 'available',
            'subject' => ['reference' => "Patient/{$s->patient_id}"],
            'started' => $s->study_date ? ($s->study_date.'T'.($s->study_time ?: '000000')) : null,
            'modality' => $s->modality ? [['system' => 'http://dicom.nema.org/resources/ontology/DCM', 'code' => $s->modality]] : [],
            'description' => $s->study_description,
            'accession' => ['reference' => "ServiceRequest/{$s->order_id}"],
        ];
    }

    private function diagnosticReportResource(Report $r): array
    {
        $conclusion = trim((string) $r->impression).($r->addendum ? " — Addendum:\n{$r->addendum}" : '');

        return [
            'resourceType' => 'DiagnosticReport',
            'id' => (string) $r->id,
            'identifier' => [['system' => 'urn:oid:1.2.3.4.5.6.7.8.9.4', 'value' => $r->report_number]],
            'status' => match ($r->status) {
                ReportStatus::Final => 'final',
                ReportStatus::Verified => 'corrected',
                ReportStatus::Cancelled => 'cancelled',
                default => 'preliminary',
            },
            'code' => ['coding' => [['code' => 'RAD', 'display' => 'Radiology']]],
            'subject' => ['reference' => "Patient/{$r->order?->patient_id}"],
            'effectiveDateTime' => $r->dictated_at?->toIso8601String(),
            'issued' => $r->finalized_at?->toIso8601String(),
            'performer' => $r->radiologist_id ? [['reference' => "Practitioner/{$r->radiologist_id}"]] : [],
            'result' => $r->study_id ? [['reference' => "ImagingStudy/{$r->study_id}"]] : [],
            'conclusion' => $conclusion !== '' ? $conclusion : null,
            'presentedForm' => [
                [
                    'contentType' => 'text/plain',
                    'data' => base64_encode((string) $r->findings),
                ],
            ],
        ];
    }
}