<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pelacakan MPPS (Modality Performed Procedure Step) — M7 (lihat Rencana §7).
 *
 * Latar: N-SET/N-ACTION tidak wajib membawa AccessionNumber, sedangkan
 * AffectedSOPInstanceUID/RequestedSOPInstanceUID selalu ada. Adapter dulu
 * menyimpan peta SOPInstanceUID → accession hanya di memori proses; bila
 * adapter restart di tengah prosedur, N-SET kehilangan accession dan order
 * tidak maju. Tabel ini menjadikan **Laravel sumber kebenaran**: N-CREATE
 * mendaftarkan peta tersebut, N-SET/N-ACTION mencarinya di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpps_records', function (Blueprint $table) {
            $table->id();
            $table->string('sop_instance_uid', 128)->unique();  // AffectedSOPInstanceUID (N-CREATE)
            $table->string('accession_number', 16)->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->nullable();           // PerformedProcedureStepStatus terakhir
            $table->string('modality', 16)->nullable();
            $table->timestamp('performed_started_at')->nullable();
            $table->timestamp('performed_ended_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('accession_number');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpps_records');
    }
};
