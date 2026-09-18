<?php

namespace Tests\Feature;

use App\Models\PacsSource;
use App\Services\PacsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PacsClientTest extends TestCase
{
    use RefreshDatabase;

    private PacsSource $pacs;
    private PacsClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pacs = PacsSource::create([
            'name' => 'Orthanc Test',
            'ae_title' => 'ORTHANC',
            'base_url' => 'http://orthanc:8042',
            'qido_url' => 'http://orthanc:8042/dicom-web',
            'wado_url' => 'http://orthanc:8042/dicom-web',
            'stow_url' => 'http://orthanc:8042/dicom-web/studies',
            'is_active' => true,
        ]);

        $this->client = app(PacsClient::class);
    }

    public function test_ping_success(): void
    {
        Http::fake([
            'http://orthanc:8042/dicom-web/studies?limit=1' => Http::response('[]', 200),
        ]);

        $this->assertTrue($this->client->ping($this->pacs));
    }

    public function test_ping_failure(): void
    {
        Http::fake([
            'http://orthanc:8042/dicom-web/studies?limit=1' => Http::response('', 503),
        ]);

        $this->assertFalse($this->client->ping($this->pacs));
    }

    public function test_qido_studies_returns_json(): void
    {
        Http::fake([
            'http://orthanc:8042/dicom-web/studies*' => Http::response(
                '[{"00080020":{"Value":["20260917"],"vr":"DA"},"0020000D":{"Value":["1.2.3.4.5"],"vr":"UI"}}]',
                200,
            ),
        ]);

        $studies = $this->client->qidoStudies($this->pacs, ['AccessionNumber' => 'ACC-123']);

        $this->assertCount(1, $studies);
        $this->assertSame('1.2.3.4.5', $studies[0]['0020000D']['Value'][0]);
    }

    public function test_qido_series_and_instances(): void
    {
        Http::fake([
            '*/series' => Http::response('[{"0020000E":{"Value":["S1"]}}]', 200),
            '*/instances' => Http::response('[{"00080018":{"Value":["I1"]}}]', 200),
        ]);

        $series = $this->client->qidoSeries($this->pacs, 'STUDY1');
        $instances = $this->client->qidoInstances($this->pacs, 'STUDY1', 'S1');

        $this->assertSame('S1', $series[0]['0020000E']['Value'][0]);
        $this->assertSame('I1', $instances[0]['00080018']['Value'][0]);
    }

    public function test_wado_rendered_returns_image(): void
    {
        Http::fake([
            '*/rendered' => Http::response('JFIF-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $img = $this->client->wadoRendered($this->pacs, 'STUDY1', 'S1', 'I1');

        $this->assertNotNull($img);
        $this->assertSame('JFIF-bytes', $img['content']);
        $this->assertSame('image/jpeg', $img['content_type']);
    }

    public function test_stow_file_success(): void
    {
        Http::fake([
            'http://orthanc:8042/dicom-web/studies' => Http::response('ok', 200),
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'stow') . '.dcm';
        file_put_contents($tmp, 'dicom-bytes');

        $result = $this->client->stowFile($this->pacs, $tmp);

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['status']);
    }

    public function test_stow_file_failure_and_unreadable(): void
    {
        Http::fake([
            'http://orthanc:8042/dicom-web/studies' => Http::response('err', 500),
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'stow') . '.dcm';
        file_put_contents($tmp, 'dicom-bytes');

        $result = $this->client->stowFile($this->pacs, $tmp);
        $this->assertFalse($result['ok']);

        $missing = '/tmp/does-not-exist-' . uniqid() . '.dcm';
        $result = $this->client->stowFile($this->pacs, $missing);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('file tidak terbaca', $result['error']);
    }

    /**
     * Orthanc produksi memakai `AuthenticationEnabled: true`; klien harus
     * mengirim Basic auth dari kolom kredensial, dan password terenkripsi di DB.
     */
    public function test_credentials_sent_as_basic_auth_and_stored_encrypted(): void
    {
        Http::fake([
            'http://orthanc:8042/dicom-web/studies*' => Http::response('[]', 200),
        ]);

        $pacs = PacsSource::create([
            'name' => 'Orthanc Auth',
            'ae_title' => 'ORTHANC',
            'base_url' => 'http://orthanc:8042',
            'qido_url' => 'http://orthanc:8042/dicom-web',
            'stow_url' => 'http://orthanc:8042/dicom-web/studies',
            'username' => 'orp',
            'password' => 'rahasia-123',
            'is_active' => true,
        ]);

        $this->assertTrue($pacs->hasCredentials());

        // Password tidak boleh tersimpan sebagai teks biasa…
        $raw = DB::table('pacs_sources')->where('id', $pacs->id)->value('password');
        $this->assertNotSame('rahasia-123', $raw);
        // …tetapi tetap bisa dibaca model (cast encrypted).
        $this->assertSame('rahasia-123', PacsSource::find($pacs->id)->password);
        // Password tidak pernah ikut serialisasi JSON.
        $this->assertArrayNotHasKey('password', PacsSource::find($pacs->id)->toArray());

        $this->assertSame([], $this->client->qidoStudies($pacs, ['limit' => '1']));

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Basic ' . base64_encode('orp:rahasia-123'));
        });
    }

    public function test_no_authorization_header_without_credentials(): void
    {
        Http::fake([
            'http://orthanc:8042/dicom-web/studies*' => Http::response('[]', 200),
        ]);

        $this->assertFalse($this->pacs->hasCredentials());
        $this->client->qidoStudies($this->pacs);

        Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
    }
}