<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Rename the table if it exists
        if (Schema::hasTable('plan_children')) {
            // First get the existing relationships and store them
            $existingRelations = [];
            if (Schema::hasTable('plan_children')) {
                $existingRelations = DB::table('plan_children')->get()->toArray();
            }
            
            // Drop the old table
            Schema::dropIfExists('plan_children');
            
            // Create the new table with all needed columns
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
            
            // Re-insert the existing relationships
            foreach ($existingRelations as $relation) {
                DB::table('plan_child')->insert([
                    'plan_id' => $relation->plan_id,
                    'child_id' => $relation->child_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } else {
            // Just create the new table if the old one doesn't exist
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
        }
        
        // Transfer data from the planned activities to the new table
        if (Schema::hasColumn('planned_activities', 'completed')) {
            // Get all planned activities with completion status
            $plannedActivities = DB::table('planned_activities')
                ->where('completed', true)
                ->get();
            
            // For each completed activity, create entries in the plan_child table
            foreach ($plannedActivities as $activity) {
                $plan = DB::table('plans')->where('id', $activity->plan_id)->first();
                if ($plan) {
                    // Get all children for this plan
                    $children = DB::table('plan_child')
                        ->where('plan_id', $plan->id)
                        ->distinct('child_id')
                        ->pluck('child_id');
                    
                    // If no children found, check for child_id on plan
                    if ($children->isEmpty() && $plan->child_id) {
                        $children = [$plan->child_id];
                    }
                    
                    // Create completion records for each child
                    foreach ($children as $childId) {
                        DB::table('plan_child')->updateOrInsert(
                            [
                                'plan_id' => $plan->id,
                                'child_id' => $childId,
                                'planned_activity_id' => $activity->id,
                            ],
                            [
                                'completed' => true,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        );
                    }
                }
            }
            
            // Remove the completed field from planned_activities
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
        
        // Copy completion data back to planned_activities
        $completions = DB::table('plan_child')
            ->whereNotNull('planned_activity_id')
            ->where('completed', true)
            ->get();
            
        foreach ($completions as $completion) {
            DB::table('planned_activities')
                ->where('id', $completion->planned_activity_id)
                ->update(['completed' => true]);
        }
        
        // Get the existing relationships before dropping the table
        $existingRelations = [];
        if (Schema::hasTable('plan_child')) {
            $existingRelations = DB::table('plan_child')
                ->select('plan_id', 'child_id')
                ->distinct()
                ->get()
                ->toArray();
        }
        
        // Drop the plan_child table
        Schema::dropIfExists('plan_child');
        
        // Recreate the old table if needed
        if (!Schema::hasTable('plan_children')) {
            Schema::create('plan_children', function (Blueprint $table) {
                $table->unsignedBigInteger('plan_id');
                $table->unsignedBigInteger('child_id');
                
                $table->foreign('plan_id')->references('id')->on('plans')->onDelete('cascade');
                $table->foreign('child_id')->references('id')->on('children')->onDelete('cascade');
                
                $table->primary(['plan_id', 'child_id']);
            });
            
            // Re-insert the existing relationships
            foreach ($existingRelations as $relation) {
                DB::table('plan_children')->insert([
                    'plan_id' => $relation->plan_id,
                    'child_id' => $relation->child_id,
                ]);
            }
        }
    }
};
