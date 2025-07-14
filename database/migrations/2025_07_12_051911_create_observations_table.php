<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->onDelete('cascade');
            $table->foreignId('child_id')->constrained()->onDelete('cascade');
            $table->date('observation_date');
            $table->text('observation_result')->nullable();
            $table->json('conclusions'); // Menyimpan kesimpulan sebagai JSON
            $table->timestamps();
            
            // Ensure unique observation per plan, child, and date
            $table->unique(['plan_id', 'child_id', 'observation_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('observations');
    }
};