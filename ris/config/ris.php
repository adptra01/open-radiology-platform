<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Viewer / aplikasi eksternal
    |--------------------------------------------------------------------------
    | URL OHIF dipakai tombol "Buka Viewer" (dibuka di tab baru dengan
    | ?StudyInstanceUIDs=<uid>). Di production pakai same-origin proxy
    | (platform/ohif-nginx.conf) sehingga tidak ada isu CORS.
    */
    'viewer' => [
        'ohif_url' => env('ORP_OHIF_URL', 'http://localhost:3000'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Orthanc (PACS lokal) — kredensial HTTP
    |--------------------------------------------------------------------------
    | Dipakai server-side (seeder PACS demo, klien internal). Orthanc produksi
    | ber-`AuthenticationEnabled: true` + `RegisteredUsers`, sedangkan akses
    | browser lewat proxy nginx yang menyuntikkan header Authorization
    | (platform/ohif-nginx.conf.template) — jadi password tidak pernah sampai
    | ke browser. Samakan nilai ini dengan ORP_ORTHANC_PASSWORD di compose.
    */
    'orthanc' => [
        'base_url' => env('ORP_ORTHANC_URL', 'http://orthanc:8042'),
        'username' => env('ORP_ORTHANC_USERNAME'),
        'password' => env('ORP_ORTHANC_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Worker (ai-worker FastAPI)
    |--------------------------------------------------------------------------
    | Dipakai healthcheck /api/health → GET /health.
    */
    'ai' => [
        'url' => env('ORP_AI_URL', 'http://pydev:8000'),
    ],
];
