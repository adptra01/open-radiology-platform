<?php

use App\Http\Controllers\Api\AiResultsController;
use App\Http\Controllers\Api\AiRunsController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DicomController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\FhirController;
use App\Http\Controllers\Api\ModalityController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PacsController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\ProcedureController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StudyController;
use App\Http\Controllers\Api\TransmissionController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — DICOM Adapter (machine-to-machine)
|--------------------------------------------------------------------------
|
| Dipakai oleh adapter Python (MWL/MPPS/C-STORE SCP). Semua endpoint
| dilindungi middleware dicom.api-key (header X-API-Key).
|
| Skema autentikasi di file ini dibedakan per konsumen:
|   /api/dicom/*   → X-API-Key  (adapter Python)
|   /api/fhir/*    → Bearer Sanctum (klien FHIR eksternal, interop)
|   sisanya        → sesi web + CSRF (dipakai halaman Inertia/SPA)
|
*/

Route::prefix('dicom')->middleware('dicom.api-key')->group(function () {
    Route::get('worklists', [DicomController::class, 'worklist']);                 // MWL C-FIND
    Route::post('mpps', [DicomController::class, 'mpps']);                        // MPPS N-CREATE/N-SET/N-ACTION
    Route::post('studies', [DicomController::class, 'storeStudy']);               // C-STORE forward
    Route::post('adapters/online', [DicomController::class, 'announceOnline']);   // heartbeat adapter
});

/*
|--------------------------------------------------------------------------
| API Routes — konsumen SPA (sesi web)
|--------------------------------------------------------------------------
|
| ⚠️ Grup `api` bawaan Laravel bersifat stateless (tanpa StartSession/Cookie),
| sehingga `auth` saja tidak akan pernah mengenali sesi browser. Karena
| endpoint di bawah dipanggil `fetch()` dari halaman Inertia, grup `web`
| (sesi + CSRF) ditambahkan eksplisit. Frontend memakai helper
| `resources/js/lib/api.ts` yang menyisipkan header X-XSRF-TOKEN dari cookie.
|
*/

$spa = ['web', 'auth'];

/**
 * Healthcheck — tanpa autentikasi (dipakai load balancer / monitoring).
 * Response 200 = healthy, 503 = degraded/unhealthy.
 */
Route::get('health', HealthController::class)->name('health');

// Reporting: CRUD + transisi DRAFT → DICTATED → VERIFIED → FINAL (M4), RBAC di controller.
Route::prefix('reports')->middleware($spa)->group(function () {
    Route::get('/', [ReportController::class, 'index']);
    Route::post('/', [ReportController::class, 'store']);
    Route::get('{report}', [ReportController::class, 'show']);
    Route::put('{report}', [ReportController::class, 'update']);
    Route::post('{report}/transition', [ReportController::class, 'transition']);
    Route::post('{report}/amendments', [ReportController::class, 'amend']);
    Route::delete('{report}', [ReportController::class, 'destroy']);
});

// Order COMPLETED yang menunggu report (dipakai dropdown halaman reporting).
// `awaiting-report` didaftarkan SEBELUM `{order}` agar tidak tertangkap binding.
Route::prefix('orders')->middleware($spa)->group(function () {
    Route::get('awaiting-report', [OrderController::class, 'awaitingReport']);
    Route::get('/', [OrderController::class, 'index']);
    Route::post('/', [OrderController::class, 'store']);
    Route::get('{order}', [OrderController::class, 'show']);
    Route::put('{order}', [OrderController::class, 'update']);
    Route::post('{order}/cancel', [OrderController::class, 'cancel']);
});

/*
|--------------------------------------------------------------------------
| API Routes — master data & operasional SPA (M7)
|--------------------------------------------------------------------------
| Semua endpoint di bawah memakai sesi web + `auth`, dan memeriksa izin
| Spatie di controller (trait AuthorizesPermissions). Tanpa izin → 403 JSON.
|
*/

// Dashboard: hanya bagian yang boleh dilihat user yang dikirim.
Route::get('dashboard', DashboardController::class)->middleware($spa);

// Master data pasien (termasuk merge duplikat).
Route::prefix('patients')->middleware($spa)->group(function () {
    Route::get('/', [PatientController::class, 'index']);
    Route::post('/', [PatientController::class, 'store']);
    Route::get('{patient}', [PatientController::class, 'show']);
    Route::put('{patient}', [PatientController::class, 'update']);
    Route::delete('{patient}', [PatientController::class, 'destroy']);
    Route::post('{patient}/merge', [PatientController::class, 'merge']);
});

// Master data dokter perujuk/radiolog.
Route::prefix('doctors')->middleware($spa)->group(function () {
    Route::get('/', [DoctorController::class, 'index']);
    Route::post('/', [DoctorController::class, 'store']);
    Route::get('{doctor}', [DoctorController::class, 'show']);
    Route::put('{doctor}', [DoctorController::class, 'update']);
    Route::delete('{doctor}', [DoctorController::class, 'destroy']);
});

// Katalog prosedur (read-only; dipakai dropdown order).
Route::prefix('procedures')->middleware($spa)->group(function () {
    Route::get('/', [ProcedureController::class, 'index']);
    Route::get('{procedure}', [ProcedureController::class, 'show']);
});

// Master data modalitas DICOM.
Route::prefix('modalities')->middleware($spa)->group(function () {
    Route::get('/', [ModalityController::class, 'index']);
    Route::post('/', [ModalityController::class, 'store']);
    Route::get('{modality}', [ModalityController::class, 'show']);
    Route::put('{modality}', [ModalityController::class, 'update']);
    Route::delete('{modality}', [ModalityController::class, 'destroy']);
});

// Penjadwalan.
Route::prefix('appointments')->middleware($spa)->group(function () {
    Route::get('/', [AppointmentController::class, 'index']);
    Route::post('/', [AppointmentController::class, 'store']);
    Route::get('{appointment}', [AppointmentController::class, 'show']);
    Route::put('{appointment}', [AppointmentController::class, 'update']);
    Route::delete('{appointment}', [AppointmentController::class, 'destroy']);
    Route::post('{appointment}/transition', [AppointmentController::class, 'transition']);
});

// Study terimpan di RIS + tautan viewer.
Route::prefix('studies')->middleware($spa)->group(function () {
    Route::get('/', [StudyController::class, 'index']);
    Route::get('{study}', [StudyController::class, 'show']);
});

// Audit trail (read-only).
Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware($spa);

// Manajemen user & role (khusus Admin/SuperAdmin).
Route::prefix('users')->middleware($spa)->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::put('{user}/roles', [UserController::class, 'updateRoles']);
});
Route::get('roles', [UserController::class, 'roles'])->middleware($spa);

// Proxy DICOMweb ke PACS vendor (M5) + CRUD sumber PACS (M7).
Route::prefix('pacs')->middleware($spa)->group(function () {
    Route::get('/', [PacsController::class, 'index']);
    Route::post('/', [PacsController::class, 'store']);
    Route::put('{pacs}', [PacsController::class, 'update']);
    Route::delete('{pacs}', [PacsController::class, 'destroy']);
    Route::get('{pacs}/ping', [PacsController::class, 'ping']);
    Route::get('{pacs}/studies', [PacsController::class, 'studies']);
    Route::get('{pacs}/studies/{studyUid}/series', [PacsController::class, 'series']);
    Route::get('{pacs}/studies/{studyUid}/series/{seriesUid}/instances', [PacsController::class, 'instances']);
    Route::get('{pacs}/studies/{studyUid}/series/{seriesUid}/instances/{instanceUid}/rendered', [PacsController::class, 'rendered']);
});

/*
|--------------------------------------------------------------------------
| API Routes — FHIR R4 stub (M5, interop)
|--------------------------------------------------------------------------
| Resource: Patient, ServiceRequest, ImagingStudy, DiagnosticReport.
| Dilindungi **token Bearer Sanctum** (`auth:sanctum`) — layak interop:
| klien FHIR mengirim `Authorization: Bearer <token>`, bukan cookie sesi web.
| Terbitkan token: `php artisan orp:issue-token {email} --name="<klien>"`.
| Semua resource butuh autentikasi (termasuk `metadata`) supaya tidak
| membocorkan capability server ke anonim.
|
*/

Route::prefix('fhir')->middleware('auth:sanctum')->group(function () {
    Route::get('metadata', [FhirController::class, 'metadata']);
    Route::get('Patient', [FhirController::class, 'patientSearch']);
    Route::get('Patient/{patient}', [FhirController::class, 'patientRead']);
    Route::get('ServiceRequest', [FhirController::class, 'serviceRequestSearch']);
    Route::get('ServiceRequest/{order}', [FhirController::class, 'serviceRequestRead']);
    Route::get('ImagingStudy', [FhirController::class, 'imagingStudySearch']);
    Route::get('ImagingStudy/{study}', [FhirController::class, 'imagingStudyRead']);
    Route::get('DiagnosticReport', [FhirController::class, 'diagnosticReportSearch']);
    Route::get('DiagnosticReport/{report}', [FhirController::class, 'diagnosticReportRead']);
});

// Antrean transmisi ke PACS (M4).
Route::prefix('transmissions')->middleware($spa)->group(function () {
    Route::get('/', [TransmissionController::class, 'index']);
    Route::get('{transmission}', [TransmissionController::class, 'show']);
    Route::post('{transmission}/retry', [TransmissionController::class, 'retry']);
});

// Hasil inferensi AI (M6, legacy compatibility).
Route::prefix('ai-results')->middleware($spa)->group(function () {
    Route::get('/', [AiResultsController::class, 'index']);
    Route::get('{aiResult}', [AiResultsController::class, 'show']);
    Route::post('run/{study}', [AiResultsController::class, 'run']);
});

// AI Gateway generik (keputusan arsitektur final M10+): canonical ai_runs.
Route::prefix('ai')->middleware($spa)->group(function () {
    Route::get('capabilities', [AiRunsController::class, 'capabilities']);
    Route::get('runs', [AiRunsController::class, 'index']);
    Route::post('runs', [AiRunsController::class, 'store']);
    Route::get('runs/{runId}', [AiRunsController::class, 'show']);
});