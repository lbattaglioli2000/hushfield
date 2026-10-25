<?php

namespace Database\Seeders;

use App\Actions\World\ImportMap;
use App\Domain\Identity\StaffRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(BlockedTermSeeder::class);

        if (is_file(resource_path('maps/town-square.json'))) {
            app(ImportMap::class)('town-square');
        }

        User::factory()->withAvatar()->staff(StaffRole::Admin)->create([
            'username' => 'Caretaker',
            'email' => 'test@example.com',
        ]);
    }
}
