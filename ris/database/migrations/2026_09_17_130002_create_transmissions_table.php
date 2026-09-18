<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Antrean transmisi DICOM ke PACS eksternal (M4).
 * Dipakai saat study diterima (C-STORE) dan harus diteruskan ke PACS
 * (STOW-RS), atau kirim report/order. Driver queue = database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transmissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pacs_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('study_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('transmission_type', 32)->default('STOW'); // STOW | REPORT
            $table->string('status', 16)->default('PENDING');         // TransmissionStatus
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->json('payload')->nullable();                      // detail kiriman
            $table->text('error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'next_attempt_at']);
            $table->index(['study_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transmissions');
    }
};