<?php

namespace App\Services;

use App\Models\PacsSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Klien PACS eksternal berbasis DICOMweb (M5).
 *
 * Vendor-neutral: bekerja dengan Orthanc, DCM4CHEE, dll selama endpoint
 * QIDO-RS / WADO-RS / STOW-RS tersedia (lihat Rencana §1).
 *
 * Method:
 *   - echo()                 : GET /instances?limit=1 (cek koneksi cepat)
 *   - qidoStudies(filter)    : cari studi (StudyInstanceUID, AccessionNumber, …)
 *   - qidoSeries(studyUid)   : daftar series suatu studi
 *   - qidoInstances(...)     : daftar instance serie
 *   - wadoRendered(...)      : ambil preview JPEG (WADO-RS rendered)
 *   - stowFile(path)         : kirim DICOM file ke PACS (STOW-RS)
 *
 * Semua berbasis PacsSource aktif dengan base_url + qido/wado/stow endpoint.
 * Tidak menyimpan state; setiap panggilan mengambil pacs dari parameter.
 */
class PacsClient
{
    /** Base URL endpoint DICOMweb. */
    public function baseUrl(PacsSource $pacs): string
    {
        return rtrim((string) ($pacs->qido_url ?: $pacs->base_url), '/');
    }

    private function http(PacsSource $pacs): PendingRequest
    {
        return $this->authorize($pacs, Http::timeout(30)
            ->withHeaders([
                'Accept' => 'application/dicom+json',
            ])
            ->baseUrl($this->baseUrl($pacs)));
    }

    /**
     * Terapkan Basic auth bila PACS mewajibkan autentikasi (mis. Orthanc
     * dengan `AuthenticationEnabled: true` + `RegisteredUsers`).
     */
    private function authorize(PacsSource $pacs, PendingRequest $request): PendingRequest
    {
        return $pacs->hasCredentials()
            ? $request->withBasicAuth((string) $pacs->username, (string) $pacs->password)
            : $request;
    }

    /** Cek koneksi: 1 query QIDO kecil. */
    public function ping(PacsSource $pacs): bool
    {
        try {
            $res = $this->http($pacs)->acceptJson()->get('/studies', ['limit' => 1]);

            return $res->successful();
        } catch (Throwable $e) {
            Log::warning('PACS ping gagal', ['pacs' => $pacs->name, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * QIDO-RS: cari studi.
     *
     * @param  array<string, string>  $filter  mis. ['AccessionNumber' => 'ACC-…']
     * @return array<int, array<string, mixed>>
     */
    public function qidoStudies(PacsSource $pacs, array $filter = []): array
    {
        $res = $this->http($pacs)->acceptJson()->get('/studies', $filter);
        if (! $res->successful()) {
            Log::warning('QIDO studies gagal', ['pacs' => $pacs->name, 'status' => $res->status(), 'body' => substr($res->body(), 0, 300)]);

            return [];
        }

        return $res->json() ?? [];
    }

    /** QIDO-RS: series study. */
    public function qidoSeries(PacsSource $pacs, string $studyUid): array
    {
        $res = $this->http($pacs)->acceptJson()->get("/studies/{$studyUid}/series");
        if (! $res->successful()) {
            return [];
        }

        return $res->json() ?? [];
    }

    /** QIDO-RS: instance dari series. */
    public function qidoInstances(PacsSource $pacs, string $studyUid, string $seriesUid): array
    {
        $res = $this->http($pacs)->acceptJson()->get("/studies/{$studyUid}/series/{$seriesUid}/instances");
        if (! $res->successful()) {
            return [];
        }

        return $res->json() ?? [];
    }

    /**
     * WADO-RS: preview rendered JPEG instance.
     * Return [content => bytes, content_type => string] atau null.
     */
    public function wadoRendered(PacsSource $pacs, string $studyUid, string $seriesUid, string $instanceUid): ?array
    {
        $url = "/studies/{$studyUid}/series/{$seriesUid}/instances/{$instanceUid}/rendered";
        $res = $this->authorize($pacs, Http::timeout(30)
            ->withHeaders(['Accept' => 'image/jpeg']))
            ->get($this->baseUrl($pacs).$url);

        if (! $res->successful()) {
            return null;
        }

        return [
            'content' => $res->body(),
            'content_type' => $res->header('Content-Type') ?: 'image/jpeg',
        ];
    }

    /**
     * STOW-RS: kirim file DICOM ke PACS.
     * Return bool sukses + body ringkas.
     */
    public function stowFile(PacsSource $pacs, string $filePath): array
    {
        if (! is_readable($filePath)) {
            return ['ok' => false, 'error' => 'file tidak terbaca'];
        }

        $stow = rtrim((string) ($pacs->stow_url ?: ''), '/');
        if ($stow === '') {
            return ['ok' => false, 'error' => 'stow_url belum dikonfigurasi'];
        }

        try {
            $res = $this->authorize($pacs, Http::timeout(90)
                ->attach(
                    'file',
                    fopen($filePath, 'r'),
                    basename((string) $filePath),
                    ['Content-Type' => 'application/dicom'],
                ))
                ->post($stow);

            return [
                'ok' => $res->successful(),
                'status' => $res->status(),
                'body' => substr($res->body(), 0, 500),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}