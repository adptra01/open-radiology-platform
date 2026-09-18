<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('mrn')->unique();
            $table->string('name');
            $table->date('birth_date')->nullable();
            $table->string('gender')->nullable();       // App\Enums\Gender
            $table->string('phone', 32)->nullable();
            $table->text('address')->nullable();
            $table->string('identity_number')->nullable(); // NIK / identitas lain
            $table->string('identity_type', 32)->nullable();
            $table->unsignedBigInteger('facility_id')->nullable(); // multi-site nanti
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};