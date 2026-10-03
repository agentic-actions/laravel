<?php

namespace Tests\Feature;

use App\Livewire\CreatePostForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Item 9: a Livewire component calls CreatePost::run(), and a Refusal becomes a field error through
 * toValidationException(). Item 13: actions:check passes inside a PHPUnit suite.
 */
class CreatePostFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_post_is_created(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(CreatePostForm::class)
            ->set('title', 'Hi')
            ->set('body', 'Hello')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('title', '')
            ->assertSee('Saved as a draft.');

        $this->assertSame(['Hi'], $user->posts()->pluck('title')->all());
    }

    public function test_a_duplicate_title_is_a_field_error(): void
    {
        $user = User::factory()->create();
        $user->posts()->create(['title' => 'Hi', 'body' => 'First', 'status' => 'draft']);

        $this->actingAs($user);

        Livewire::test(CreatePostForm::class)
            ->set('title', 'Hi')
            ->set('body', 'Again')
            ->call('save')
            ->assertHasErrors(['title'])
            ->assertSee('You already have a post with that title.');

        $this->assertSame(1, $user->posts()->count());
    }

    public function test_invalid_input_is_a_field_error(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreatePostForm::class)
            ->set('title', 'Hi')
            ->call('save')
            ->assertHasErrors(['body']);
    }

    public function test_actions_check_passes(): void
    {
        $this->artisan('actions:check')
            ->expectsOutputToContain('Every check passed')
            ->assertSuccessful();
    }
}
