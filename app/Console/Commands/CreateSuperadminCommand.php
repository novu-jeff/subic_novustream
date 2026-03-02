<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateSuperadminCommand extends Command
{
    protected $signature = 'superadmin:create
                            {email : Superadmin email}
                            {name : Display name}
                            {password : Password}';

    protected $description = 'Create or update a superadmin user';

    public function handle(): int
    {
        $email = $this->argument('email');
        $name = $this->argument('name');
        $password = $this->argument('password');

        $admin = Admin::where('email', $email)->first();

        if ($admin) {
            $admin->update([
                'name' => $name,
                'user_type' => 'superadmin',
                'password' => Hash::make($password),
            ]);
            $this->info("Updated superadmin: {$email}");
        } else {
            Admin::create([
                'name' => $name,
                'email' => $email,
                'user_type' => 'superadmin',
                'password' => Hash::make($password),
            ]);
            $this->info("Created superadmin: {$email}");
        }

        return self::SUCCESS;
    }
}
