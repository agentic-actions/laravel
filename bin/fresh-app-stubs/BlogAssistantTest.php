<?php

namespace Tests\Feature;

use AgenticActions\Facades\Actions;
use AgenticActions\Testing\ActionAssertions;
use App\Ai\BlogAssistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\TestCase;

/**
 * Item 12: after composer require laravel/ai, CreatePost is in the default toolset, and an agent calls it through
 * laravel/ai's fake gateway. After actions:install --copilot --tenancy, the conversation store keeps one conversation
 * per person, agent and tenant in the tables it published.
 */
class BlogAssistantTest extends TestCase
{
    use ActionAssertions;
    use RefreshDatabase;

    public function test_the_default_toolset_holds_create_post(): void
    {
        $this->assertToolset('default', ['create-post']);
        $this->assertAgentTools(new BlogAssistant(User::factory()->create()));
    }

    public function test_the_assistant_drafts_a_post_through_the_fake_gateway(): void
    {
        $user = User::factory()->create();

        BlogAssistant::fake([new ToolCall('call_1', 'create-post', ['title' => 'Hi', 'body' => 'Hello']), 'Drafted.']);

        $response = (new BlogAssistant($user))->prompt('Draft a post called Hi.');

        $this->assertSame('Drafted.', $response->text);
        $this->assertSame('Done.', $response->toolResults->first()->result);
        $this->assertSame(['Hi'], $user->posts()->pluck('title')->all());
    }

    public function test_an_invalid_call_is_answered_without_a_write(): void
    {
        $user = User::factory()->create();

        BlogAssistant::fake([new ToolCall('call_1', 'create-post', ['title' => 'Hi']), 'Sorry.']);

        $response = (new BlogAssistant($user))->prompt('Draft a post called Hi.');

        $this->assertSame('Not done. Rejected: body (required).', $response->toolResults->first()->result);
        $this->assertSame(0, $user->posts()->count());
    }

    public function test_the_conversation_store_keeps_one_conversation_per_person_and_tenant(): void
    {
        [$author, $other] = User::factory()->count(2)->create();
        $post = $author->posts()->create(['title' => 'Hi', 'body' => 'Hello', 'status' => 'draft']);

        $mine = Actions::conversation(BlogAssistant::class, $author)->open();

        $this->assertSame($mine, Actions::conversation(BlogAssistant::class, $author)->id());
        $this->assertNull(Actions::conversation(BlogAssistant::class, $other)->id());
        $this->assertNull(Actions::conversation(BlogAssistant::class, $author, $post)->id());
        $this->assertNotSame($mine, Actions::conversation(BlogAssistant::class, $author, $post)->open());
        $this->assertTrue(app(ConversationStore::class)->conversationBelongsTo($mine, $author->getMorphClass(), $author->id));
    }
}
