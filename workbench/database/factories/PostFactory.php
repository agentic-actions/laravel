<?php

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;

/**
 * @extends Factory<Post>
 */
final class PostFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Post>
     */
    protected $model = Post::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'team_id' => null,
            'title' => fake()->sentence(3),
            'body' => fake()->paragraph(),
            'excerpt' => null,
            'status' => 'draft',
        ];
    }

    /**
     * Indicate that the post belongs to the given team.
     */
    public function forTeam(Team $team): static
    {
        return $this->state(fn (array $attributes) => [
            'team_id' => $team->getKey(),
        ]);
    }

    /**
     * Indicate that the post is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'published',
        ]);
    }
}
