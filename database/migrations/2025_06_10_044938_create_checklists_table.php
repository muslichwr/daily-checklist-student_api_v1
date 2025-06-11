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
        Schema::create('checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->timestamp('assigned_date');
            $table->timestamp('due_date')->nullable();
            $table->string('status')->default('pending'); // 'pending', 'in-progress', 'completed'
            $table->json('custom_steps_used'); // Which teacher's steps were used
            $table->timestamps();
        });

        // Create a separate table for home observations
        Schema::create('home_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained()->cascadeOnDelete();
            $table->boolean('completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration')->nullable(); // in minutes
            $table->integer('engagement')->nullable(); // 1-5 stars
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Create a separate table for school observations
        Schema::create('school_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained()->cascadeOnDelete();
            $table->boolean('completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration')->nullable(); // in minutes
            $table->integer('engagement')->nullable(); // 1-5 stars
            $table->text('notes')->nullable();
            $table->text('learning_outcomes')->nullable(); // Only for school observations
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_observations');
        Schema::dropIfExists('home_observations');
        Schema::dropIfExists('checklists');
    }
};
