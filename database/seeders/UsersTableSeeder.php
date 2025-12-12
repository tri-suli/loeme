<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            ['name' => 'user1', 'email' => 'user1@example.com', 'balance' => 100000],
            ['name' => 'user2', 'email' => 'user2@example.com', 'balance' => 200000],
            ['name' => 'user3', 'email' => 'user3@example.com', 'balance' => 300000],
            ['name' => 'user4', 'email' => 'user4@example.com', 'balance' => 400000],
            ['name' => 'user5', 'email' => 'user5@example.com', 'balance' => 500000],
        ];

        foreach ($users as $user) {
            User::factory()->create($user);
        }
    }
}
