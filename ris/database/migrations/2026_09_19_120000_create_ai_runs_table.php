<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical AI run records (keputusan arsitektur final M10+).
 *
 * ai_runs = canonical source (task generik, envelope terkunci).
 * ai_results + raw_report['tb'] = legacy/read-only compatibility layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            // Human-readable run id untuk API/audit: AIR-YYMMDD-XXXX (15 char,
            // konsisten dengan ACC-/ORD-YYMMDD-XXXX).
            $table->string('run_id', 32)->unique();
            $table->foreignId('study_id')->constrained('studies')->cascadeOnDelete();
            // DICOM SeriesInstanceUID sebagai referensi string (tidak ada tabel series).
            $table->string('series_id', 128)->nullable();
            $table->string('task_id', 64);
            $table->string('model_id', 64)->nullable();
            $table->string('model_version', 16)->nullable();
            $table->decimal('threshold', 5, 4)->nullable();
            // queued → running → completed | failed | cancelled (tidak ada null=negative).
            $table->string('status', 16)->default('queued');
            $table->json('input_reference')->nullable();
            // Envelope generik terkunci (result.type: classification | detection | ...).
            $table->json('result')->nullable();
            $table->json('metadata')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('study_id');
            $table->index('series_id');
            $table->index('task_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
