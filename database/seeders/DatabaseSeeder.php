<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $password = (string) config('services.admin.password');

        if ($password === '') {
            throw new \RuntimeException('ADMIN_PASSWORD must be configured before seeding.');
        }

        User::query()->updateOrCreate(
            ['name' => (string) config('services.admin.name', 'ningredy')],
            [
                'email' => (string) config('services.admin.email', 'ningredy@local.test'),
                'password' => $password,
                'is_admin' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
