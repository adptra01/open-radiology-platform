<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studies', function (Blueprint $table) {
            $table->id();
            $table->string('accession_number')->nullable()->index();
            $table->string('study_instance_uid')->index();
            $table->string('series_instance_uid')->nullable();
            $table->string('sop_instance_uid')->unique();
            $table->string('sop_class_uid')->nullable();
            $table->string('modality', 16)->nullable();
            $table->string('study_description')->nullable();
            $table->string('study_date', 8)->nullable();
            $table->string('study_time', 16)->nullable();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_path')->nullable(); // path di inbox adapter bila disimpan
            $table->boolean('matched')->default(false)->index();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studies');
    }
};