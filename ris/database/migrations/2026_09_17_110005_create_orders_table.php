<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->string('accession_number')->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referring_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->foreignId('procedure_id')->constrained()->restrictOnDelete();
            $table->foreignId('modality_id')->nullable()->constrained()->nullOnDelete();
            $table->string('priority', 16)->default('ROUTINE'); // App\Enums\OrderPriority
            $table->string('status', 32)->default('REQUESTED'); // App\Enums\OrderStatus
            $table->text('status_note')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('facility_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};