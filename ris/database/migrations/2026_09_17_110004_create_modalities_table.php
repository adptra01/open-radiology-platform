<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modalities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ae_title', 16)->unique();  // DICOM AE title (uppercase)
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('modality_type', 16)->nullable(); // CR/CT/MR/US...
            $table->string('location')->nullable();
            $table->boolean('is_online')->default(false);
            $table->unsignedBigInteger('facility_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modalities');
    }
};