<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('study_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('radiologist_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report_number')->unique();
            $table->string('status')->default('DRAFT');
            $table->text('findings')->nullable();
            $table->text('impression')->nullable();
            $table->text('addendum')->nullable();
            $table->timestamp('dictated_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};