<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Terbitkan token Sanctum untuk akses API FHIR (M5) pada klien eksternal.
 *
 * Contoh:
 *   php artisan orp:issue-token fhir@client.test --name="EMR RSUD"
 *   php artisan orp:issue-token fhir@client.test --name="EMR RSUD" --ability=fhir:read --expires=365
 *
 * Token hanya dicetak sekali (yang tersimpan di DB adalah hash-nya).
 */
class IssueApiToken extends Command
{
    protected $signature = 'orp:issue-token
        {email : Email user pemilik token}
        {--name=fhir-client : Nama klien (untuk audit/revoke)}
        {--ability=* : Ability yang diberikan (default fhir:read)}
        {--expires= : Umur token dalam hari (kosong = tanpa kedaluwarsa)}';

    protected $description = 'Terbitkan token API Sanctum untuk akses FHIR (Bearer token)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("User dengan email {$this->argument('email')} tidak ditemukan.");

            return self::FAILURE;
        }

        $abilities = $this->option('ability') ?: ['fhir:read'];
        $expiresAt = $this->option('expires') !== null
            ? now()->addDays((int) $this->option('expires'))
            : null;

        $token = $user->createToken($this->option('name'), $abilities, $expiresAt);

        $this->info('Token dibuat. Simpan sekarang — tidak bisa ditampilkan lagi.');
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->line('Pakai: Authorization: Bearer <token>');
        $this->line('Contoh: curl -H "Authorization: Bearer <token>" -H "Accept: application/fhir+json" ' . url('/api/fhir/metadata'));

        return self::SUCCESS;
    }
}
