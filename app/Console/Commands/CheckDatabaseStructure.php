<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckDatabaseStructure extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:check-structure';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the database structure for plan_child table';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking database structure...');
        
        // Check if plan_child table exists
        if (Schema::hasTable('plan_child')) {
            $this->info('plan_child table exists.');
            
            // Get table structure
            $columns = Schema::getColumnListing('plan_child');
            $this->info('Columns in plan_child table: ' . implode(', ', $columns));
            
            // Check for sample data
            $sampleData = DB::table('plan_child')->limit(10)->get();
            
            if ($sampleData->count() > 0) {
                $this->info('Sample data in plan_child table:');
                foreach ($sampleData as $row) {
                    $this->info(json_encode([
                        'id' => $row->id,
                        'plan_id' => $row->plan_id,
                        'child_id' => $row->child_id,
                        'planned_activity_id' => $row->planned_activity_id,
                        'completed' => $row->completed,
                    ]));
                }
            } else {
                $this->warn('No data found in plan_child table.');
            }
            
        } else {
            $this->error('plan_child table does not exist!');
        }
        
        // Check if planned_activities table exists
        if (Schema::hasTable('planned_activities')) {
            $this->info('planned_activities table exists.');
            
            // Get table structure
            $columns = Schema::getColumnListing('planned_activities');
            $this->info('Columns in planned_activities table: ' . implode(', ', $columns));
            
            // Check if completed column is gone
            if (in_array('completed', $columns)) {
                $this->error('completed column still exists in planned_activities table!');
            } else {
                $this->info('completed column has been removed from planned_activities table as expected.');
            }
        } else {
            $this->error('planned_activities table does not exist!');
        }
        
        return 0;
    }
} 