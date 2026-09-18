<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function makeLog(int $daysAgo, array $extra = []): AuditLog
    {
        $log = AuditLog::create(array_merge([
            'action' => 'test.action',
            'subject_type' => 'Test',
            'subject_id' => 1,
            'properties' => ['note' => "log {$daysAgo} days ago"],
        ], $extra));

        // Force set created_at (mass assignment tidak include timestamps)
        $log->created_at = CarbonImmutable::now()->subDays($daysAgo);
        $log->save();

        return $log;
    }

    public function test_cleanup_deletes_old_logs_in_batches(): void
    {
        // 3 log lama (>90 hari), 2 log baru
        $this->makeLog(100);
        $this->makeLog(101);
        $this->makeLog(102);
        $this->makeLog(10);
        $this->makeLog(11);

        $this->assertEquals(5, AuditLog::count());

        $this->artisan('auditlog:cleanup', ['--days' => 90, '--batch' => 2])
            ->assertExitCode(0);

        $this->assertEquals(2, AuditLog::count());
    }

    public function test_cleanup_dry_run_does_not_delete(): void
    {
        $this->makeLog(100);
        $this->makeLog(101);
        $this->makeLog(102);

        $this->artisan('auditlog:cleanup', ['--days' => 90, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertEquals(3, AuditLog::count());
    }

    public function test_cleanup_respects_retention_days(): void
    {
        $this->makeLog(89); // tidak dihapus
        $this->makeLog(91); // dihapus

        $this->artisan('auditlog:cleanup', ['--days' => 90])
            ->assertExitCode(0);

        $this->assertEquals(1, AuditLog::count());
    }
}