<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Workbench\Database\Factories\TeamFactory;
use Workbench\Database\Factories\UserFactory;

final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the workbench's database with one author who belongs to one team.
     */
    public function run(): void
    {
        $user = UserFactory::new()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $team = TeamFactory::new()->create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $team->users()->attach($user);
    }
}
