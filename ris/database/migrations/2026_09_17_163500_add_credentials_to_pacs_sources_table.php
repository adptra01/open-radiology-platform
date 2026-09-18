<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kredensial HTTP (Basic auth) untuk PACS yang mewajibkan autentikasi —
 * mis. Orthanc dengan `AuthenticationEnabled: true` + `RegisteredUsers`.
 *
 * `password` disimpan sebagai teks terenkripsi (cast `encrypted` pada model),
 * jadi kolomnya TEXT: ciphertext Laravel jauh lebih panjang dari 255 char.
 * Akses HTTP dari browser tetap lewat proxy yang menyuntikkan header
 * Authorization (lihat platform/ohif-nginx.conf.template), sedangkan klien
 * server-side (PacsClient) memakai kredensial di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pacs_sources', function (Blueprint $table) {
            $table->string('username')->nullable()->after('stow_url');
            $table->text('password')->nullable()->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('pacs_sources', function (Blueprint $table) {
            $table->dropColumn(['username', 'password']);
        });
    }
};
