<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasil inferensi AI (CXR screening) per study (M6).
 * Alur: C-STORE → study → dispatch RunAiInference → POST ai-worker /infer → simpan di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('PENDING');  // PENDING|PROCESSING|COMPLETED|FAILED
            $table->string('model_name', 64)->default('densenet121-res224-all');
            $table->json('pathologies')->nullable();   // semua skor model {pathology: score}
            $table->json('findings')->nullable();      // di atas threshold [{name, score}]
            $table->json('raw_report')->nullable();    // JSON response utuh dari ai-worker
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('inference_ms')->nullable();  // waktu inferensi ms
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('study_id');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_results');
    }
};