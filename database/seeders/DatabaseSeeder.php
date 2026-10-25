<?php

namespace Database\Seeders;

use App\Actions\World\ImportMap;
use App\Domain\Identity\StaffRole;
use App\Domain\World\MapFiles;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(BlockedTermSeeder::class);

        $room = (string) config('hushfield.world.default_room');

        if (is_file(app(MapFiles::class)->pathFor($room))) {
            app(ImportMap::class)($room);
        }

        User::factory()->withAvatar()->staff(StaffRole::Admin)->create([
            'username' => 'Caretaker',
            'email' => 'test@example.com',
        ]);
    }
}
