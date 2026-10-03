<?php

namespace Tests\Feature\Testing;

use AgenticActions\ActionContext;
use AgenticActions\Discovery\Snapshot;
use AgenticActions\Facades\Actions;
use AgenticActions\Refusal;
use AgenticActions\Testing\ActionAssertions;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\Tool;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\ListNotes;
use Tests\TestCase;
use Workbench\App\Models\User;

/**
 * The kit in a PHPUnit test class, with no Pest function anywhere, as an app without Pest writes it.
 */
final class PhpUnitStyleTest extends TestCase
{
    use ActionAssertions;

    public function test_assert_toolset_passes_for_the_exact_list(): void
    {
        $this->assertToolset('support', []);

        if (interface_exists(Tool::class)) {
            $this->assertToolset('default', ['create-note', 'hidden-note', 'late-authorize', 'list-notes', 'team-note', 'translated-note']);
        }
    }

    public function test_assert_toolset_fails_naming_the_missing_action(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('The [support] toolset does not hold exactly the expected actions. Extra: none. Missing: create-note.');

        $this->assertToolset('support', ['create-note']);
    }

    public function test_the_fake_records_calls_and_answers_them(): void
    {
        $user = User::factory()->create();
        $fake = Actions::fake([
            CreateNote::class => ['id' => 1, 'title' => 'Hi'],
            ListNotes::class => Refusal::make('Not now.'),
        ]);

        $this->assertSame(['id' => 1, 'title' => 'Hi'], CreateNote::run(['title' => 'Hi', 'body' => 'x'], ActionContext::http($user)));

        $this->assertTrue(Actions::attempt(ListNotes::class, [], ActionContext::http($user))->refused());

        $fake->assertRan(CreateNote::class, fn (array $input, ActionContext $context): bool => $input['title'] === 'Hi' && $context->actor?->getAuthIdentifier() === $user->getKey());
        $fake->assertRanTimes('list-notes', 1);
        $fake->assertNotRan('plain-note');
    }

    public function test_actions_check_asks_for_the_snapshot_then_passes(): void
    {
        File::delete(Snapshot::path());

        try {
            $this->artisan('actions:check')
                ->expectsOutputToContain('is missing: run php artisan actions:check --update')
                ->assertFailed();

            $this->artisan('actions:check', ['--update' => true])->assertSuccessful();
            $this->artisan('actions:check')->assertSuccessful();
        } finally {
            File::delete(Snapshot::path());
        }
    }

    public function test_a_failed_fake_assertion_is_a_phpunit_failure(): void
    {
        $fake = Actions::fake();

        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('The expected action [create-note] did not run.');

        $fake->assertRan('create-note');
    }
}
