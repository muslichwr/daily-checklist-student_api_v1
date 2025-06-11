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
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->string('environment'); // 'Home', 'School', 'Both'
            $table->string('difficulty'); // 'Easy', 'Medium', 'Hard'
            $table->integer('min_age');
            $table->integer('max_age');
            $table->foreignId('next_activity_id')->nullable()->constrained('activities')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        // Create a separate table for custom steps
        Schema::create('activity_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->json('steps'); // Store steps as JSON array
            $table->timestamps();
            
            // Ensure each teacher has only one set of steps per activity
            $table->unique(['activity_id', 'teacher_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_steps');
        Schema::dropIfExists('activities');
    }
};
