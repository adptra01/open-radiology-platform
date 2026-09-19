<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amendment sebagai record baru yang mereferensikan report FINAL
 * (keputusan M12.1: FINAL immutable — edit/delete/overwrite dilarang,
 * koreksi hanya via amendment). Histori klinis tidak hilang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('parent_report_id')->nullable()->constrained('reports')->nullOnDelete();
            $table->index('parent_report_id');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_report_id');
        });
    }
};
