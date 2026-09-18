<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Membersihkan audit log lama (retention policy).
 *
 * Default: hapus log > 90 hari, batch 1000 baris per iterasi.
 * Bisa dijalankan via scheduler harian:
 *   $schedule->command('auditlog:cleanup')->dailyAt('03:00');
 */
class AuditLogCleanup extends Command
{
    protected $signature = 'auditlog:cleanup
        {--days=90 : Retensi hari (log lebih tua dihapus)}
        {--batch=1000 : Jumlah baris per batch DELETE}
        {--dry-run : Tampilkan yang akan dihapus tanpa eksekusi}';

    protected $description = 'Hapus audit log melewati retensi (default 90 hari)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $batch = (int) $this->option('batch');
        $dryRun = $this->option('dry-run');

        if ($days < 1) {
            $this->error('Retensi minimal 1 hari.');
            return self::FAILURE;
        }

        $cutoff = CarbonImmutable::now()->subDays($days);

        $this->info("Audit log cleanup — cutoff: {$cutoff->toDateString()} (retensi {$days} hari)");
        $this->info("Batch size: {$batch}");

        $deletedTotal = 0;

        while (true) {
            $query = AuditLog::query()
                ->where('created_at', '<', $cutoff)
                ->limit($batch);

            $count = $query->count();

            if ($count === 0) {
                break;
            }

            if ($dryRun) {
                $this->line("  [DRY RUN] Akan hapus {$count} baris (sisa estimasi: will loop)");
                break;
            }

            $deleted = $query->delete();
            $deletedTotal += $deleted;

            $this->line("  Deleted {$deleted} baris (total: {$deletedTotal})");

            if ($deleted < $batch) {
                break;
            }
        }

        if ($dryRun) {
            $this->info('[DRY RUN] Selesai — tidak ada data benar-benar dihapus.');
        } else {
            $this->info("Selesai — total {$deletedTotal} baris audit log dihapus.");
        }

        return self::SUCCESS;
    }
}