<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    // Command signature
    protected $signature = 'make:admin
                            {--email= : Admin email address}
                            {--password= : Admin password}';

    protected $description = 'Create an admin user safely';

    public function handle()
    {
        // Environment check (production safe)
        if (!app()->environment(['local', 'staging', 'production'])) {
            $this->error('This command is not allowed in this environment.');
            return 1;
        }

        // Get email & password from options or env
        $email = $this->option('email') ?? env('ADMIN_EMAIL', 'admin@example.com');
        $password = $this->option('password') ?? env('ADMIN_PASSWORD', 'password123');

        // Check if admin already exists
        if (User::where('email', $email)->exists()) {
            $this->info('Admin with this email already exists.');
            return 0;
        }

        // Create admin
        $admin = User::create([
            'first_name' => 'Admin',
            'last_name'  => 'User',
            'email'      => $email,
            'phone'      => '0000000000', // optional placeholder
            'password'   => Hash::make($password),
            'role'       => 'admin',
        ]);

        $this->info('Admin created successfully!');
        $this->info("Email: {$email}");
        $this->info("Password: {$password}");

        return 0;
    }
}
