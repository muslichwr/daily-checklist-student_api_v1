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
        Schema::table('activity_steps', function (Blueprint $table) {
            // Add photos column as JSON
            $table->json('photos')->nullable()->after('steps');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_steps', function (Blueprint $table) {
            // Remove photos column
            $table->dropColumn('photos');
        });
    }
};
