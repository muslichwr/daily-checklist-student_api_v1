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
        Schema::table('activities', function (Blueprint $table) {
            // Add duration column
            $table->integer('duration')->nullable()->after('max_age');
            
            // Modify min_age and max_age columns to decimal to support half-year increments
            $table->decimal('min_age', 3, 1)->change();
            $table->decimal('max_age', 3, 1)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            // Remove duration column
            $table->dropColumn('duration');
            
            // Change min_age and max_age columns back to integer
            $table->integer('min_age')->change();
            $table->integer('max_age')->change();
        });
    }
};
