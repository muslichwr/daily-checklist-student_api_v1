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
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('parent')->after('email');
            $table->foreignId('created_by')->nullable()->after('role')->constrained('users')->nullOnDelete();
            $table->boolean('is_temp_password')->default(false)->after('password');
            $table->string('phone_number')->nullable()->after('is_temp_password');
            $table->text('address')->nullable()->after('phone_number');
            $table->string('profile_picture')->nullable()->after('address');
            $table->string('status')->default('active')->after('profile_picture');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn([
                'role',
                'created_by',
                'is_temp_password',
                'phone_number',
                'address',
                'profile_picture',
                'status'
            ]);
        });
    }
};
