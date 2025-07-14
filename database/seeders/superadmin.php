<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class superadmin extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if superadmin already exists
        $existingSuperadmin = User::where('email', 'superadmin@mail.com')->first();
        
        if (!$existingSuperadmin) {
            User::create([
                'name' => 'Super Admin',
                'email' => 'superadmin@mail.com',
                'password' => 'password123',
                'role' => 'superadmin',
                'created_by' => null, // Superadmin is self-created
                'is_temp_password' => false,
                'phone_number' => null,
                'address' => null,
                'profile_picture' => null,
                'status' => 'active',
                'fcm_token' => null,
            ]);
            
            $this->command->info('Superadmin account created successfully!');
        } else {
            $this->command->info('Superadmin account already exists!');
        }
    }
}
