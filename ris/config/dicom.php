<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DICOM Integration (adapter ↔ RIS)
    |--------------------------------------------------------------------------
    |
    | Shared secret yang dikirim DICOM adapter Python via header X-API-Key.
    | Wajib diisi di .env (ORP_RIS_API_KEY); kosong = endpoint ditolak 401.
    |
    */

    'api_key' => env('ORP_RIS_API_KEY', ''),
];