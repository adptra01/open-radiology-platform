<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacs_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ae_title', 16)->nullable();
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('base_url')->nullable();     // root HTTP PACS
            $table->string('qido_url')->nullable();     // QIDO-RS
            $table->string('wado_url')->nullable();     // WADO-RS
            $table->string('stow_url')->nullable();     // STOW-RS
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('facility_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pacs_sources');
    }
};