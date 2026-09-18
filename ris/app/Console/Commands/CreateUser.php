<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Buat (atau update) user + role tanpa factory/faker.
 *
 * Password TIDAK pernah dicetak: diambil dari --password atau env
 * (mis. ADMIN_PASSWORD di .env.prod). Dipakai untuk bootstrap
 * login pertama di server produksi/trial yang image-nya --no-dev.
 *
 *   php artisan orp:create-user --name="Super Admin" --email=a@b.c \
 *       --role=super-admin
 *   # password dibaca dari env ADMIN_PASSWORD
 */
class CreateUser extends Command
{
    protected $signature = 'orp:create-user
        {--name= : Nama user}
        {--email= : Email login (unik)}
        {--role= : Role spatie (mis. super-admin)}
        {--password= : Password (disarankan via --password-env)}
        {--password-env=ADMIN_PASSWORD : Nama env berisi password}';

    protected $description = 'Buat/update user + role tanpa factory (bootstrap prod)';

    public function handle(): int
    {
        $name = (string) ($this->option('name') ?: '');
        $email = (string) ($this->option('email') ?: '');
        $role = (string) ($this->option('role') ?: '');
        $password = (string) ($this->option('password') ?: '');
        if ($password === '') {
            $password = (string) env((string) $this->option('password-env'), '');
        }

        if ($name === '' || $email === '' || $role === '' || $password === '') {
            $this->error('name, email, role, dan password (opsi/env) wajib diisi.');

            return self::FAILURE;
        }

        if (! Role::where('name', $role)->exists()) {
            $this->error("Role '{$role}' tidak dikenal.");

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]
        );
        $user->assignRole($role);

        $this->info("OK: {$email} (role: {$role})");

        return self::SUCCESS;
    }
}
