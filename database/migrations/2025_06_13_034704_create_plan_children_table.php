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
        // Rename the table if it exists
        if (Schema::hasTable('plan_children')) {
            Schema::rename('plan_children', 'plan_child');
        }
        
        // Create or modify the plan_child table
        Schema::create('plan_child', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('child_id');
            $table->unsignedBigInteger('planned_activity_id')->nullable();
            $table->boolean('completed')->default(false);
            $table->timestamps();
            
            $table->foreign('plan_id')->references('id')->on('plans')->onDelete('cascade');
            $table->foreign('child_id')->references('id')->on('children')->onDelete('cascade');
            $table->foreign('planned_activity_id')->references('id')->on('planned_activities')->onDelete('cascade');
            
            // Unique constraint to prevent duplicate entries
            $table->unique(['plan_id', 'child_id', 'planned_activity_id'], 'plan_child_unique');
        });
        
        // Remove the completed field from planned_activities if it exists
        if (Schema::hasColumn('planned_activities', 'completed')) {
            Schema::table('planned_activities', function (Blueprint $table) {
                $table->dropColumn('completed');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add back the completed field to planned_activities
        Schema::table('planned_activities', function (Blueprint $table) {
            $table->boolean('completed')->default(false);
        });
        
        // Drop the plan_child table
        Schema::dropIfExists('plan_child');
        
        // Recreate the old table if needed
        Schema::create('plan_children', function (Blueprint $table) {
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('child_id');
            
            $table->foreign('plan_id')->references('id')->on('plans')->onDelete('cascade');
            $table->foreign('child_id')->references('id')->on('children')->onDelete('cascade');
            
            $table->primary(['plan_id', 'child_id']);
        });
    }
};
