<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(['username' => 'kring'], [
            'name' => 'Kring',
            'password' => 'pass1234',
            'passcode' => '0428',
            'role' => 'manager',
            'status' => 'active',
        ]);

        User::updateOrCreate(['username' => 'dangbi'], [
            'name' => 'Dangbi',
            'password' => 'pass1234',
            'passcode' => null,
            'role' => 'cashier',
            'status' => 'active',
        ]);
    }
}
