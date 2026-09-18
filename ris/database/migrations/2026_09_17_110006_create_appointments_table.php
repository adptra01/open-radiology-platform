<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modality_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('scheduled_at');
            $table->string('status', 32)->default('SCHEDULED'); // App\Enums\AppointmentStatus
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('facility_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['scheduled_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};