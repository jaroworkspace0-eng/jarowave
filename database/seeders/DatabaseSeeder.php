<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Client;
use App\Models\Channel;
use App\Models\Employee;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Karabo',
            'email' => 'karabo@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        User::factory()->create([
            'name' => 'Abram',
            'email' => 'abram@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        //    Client::create([
        //     'name' => 'jaro',
        //     'phone' => '0622219995',
        //     'email' => 'joe@example.com',
        //     'address' => 'germiston',
        // ]);
        //      Channel::create([
        //     'client_id' => 4,
        //     'name' => 'Dycom4 Channel',
        //     'category' => 'Securty',
        //     'type' => 'listen',
        // ]);
        // Employee::create([
        // 'first_name' => 'abram',
        // 'last_name' => 'blessed',
        // 'email' => 'abram@gmail.com',
        // 'phone' => '01223456788',
        // 'occupation' => 'Developer',
        // 'client_id' => 4,
        // 'channel_id' => 4

        // ]);
    }
}
