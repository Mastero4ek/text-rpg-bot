<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $name = config('app.admin.name');
        $email = config('app.admin.email');
        $password = config('app.admin.password');

        if (! is_string($name) || $name === '') {
            throw new RuntimeException('ADMIN_NAME is not set.');
        }

        if (! is_string($email) || $email === '') {
            throw new RuntimeException('ADMIN_EMAIL is not set.');
        }

        if (! is_string($password) || $password === '') {
            throw new RuntimeException('ADMIN_PASSWORD is not set.');
        }

        User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
            ],
        );
    }
}
