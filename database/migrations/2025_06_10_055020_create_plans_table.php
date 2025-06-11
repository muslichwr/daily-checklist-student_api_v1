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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users');
            $table->string('type')->default('weekly'); // 'weekly', 'daily'
            $table->date('start_date');
            $table->foreignId('child_id')->nullable()->constrained('children');
            $table->timestamps();
        });

        Schema::create('planned_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->onDelete('cascade');
            $table->foreignId('activity_id')->constrained('activities');
            $table->date('scheduled_date');
            $table->string('scheduled_time')->nullable(); // Format: 'HH:MM'
            $table->boolean('reminder')->default(true);
            $table->boolean('completed')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planned_activities');
        Schema::dropIfExists('plans');
    }
}; 