<?php

use AgenticActions\ActionContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Tests\Fixtures\Checks\RunEcho;
use Tests\Fixtures\Datasets\PostNumbers;
use Tests\Fixtures\Views\PostStats;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

beforeEach(function () {
    // The default guard's provider must hand back the workbench's users.
    config(['auth.providers.users.model' => User::class]);

    $this->user = User::factory()->create();
    $this->as = (string) $this->user->getKey();
});

/**
 * Run actions:run and return its exit code and everything it printed, stdout and stderr together.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function runActionCommand(array $parameters): array
{
    $code = Artisan::call('actions:run', $parameters);

    return [$code, Artisan::output()];
}

describe('success', function () {
    it('prints the projected output as pretty JSON and exits 0', function () {
        $this->artisan("actions:run create-note title=Hi body=x --as={$this->as}")->assertExitCode(0);

        $post = Post::query()->sole();

        [$code, $output] = runActionCommand(['name' => 'create-note', 'input' => ['title=Again', 'body=y'], '--as' => $this->as]);
        $again = Post::query()->whereKeyNot($post->getKey())->sole();

        expect($post->title)->toBe('Hi')
            ->and($post->user_id)->toBe($this->user->getKey())
            ->and($code)->toBe(0)
            ->and($output)->toBe(json_encode(['id' => $again->getKey(), 'title' => 'Again'], JSON_PRETTY_PRINT)."\n");
    });

    it('prints {} for an action without output', function () {
        [$code, $output] = runActionCommand(['name' => 'guest-lookup']);

        expect($code)->toBe(0)->and($output)->toBe("{}\n");
    });

    it('runs without --as as a guest, and authorize() decides', function () {
        [$guestCode] = runActionCommand(['name' => 'guest-lookup']);
        [$deniedCode, $denied] = runActionCommand(['name' => 'create-note', 'input' => ['title=Hi', 'body=x']]);

        expect($guestCode)->toBe(0)
            ->and($deniedCode)->toBe(3)
            ->and($denied)->toBe(trans('agentic-actions::http.denied')."\nNo --as was given, so the action ran without a user.\n")
            ->and(Post::query()->count())->toBe(0);
    });
});

describe('input', function () {
    beforeEach(function () {
        config(['agentic-actions.discovery.classes' => [RunEcho::class]]);
        $this->refreshActions();
    });

    it('reads a --input object with nested and null values, and sets key=value pairs and dotted keys on top', function () {
        [$code, $output] = runActionCommand([
            'name' => 'run-echo',
            'input' => ['title=From a pair', 'author.age=41', 'tags.1=second', 'count=12', 'draft=off'],
            '--input' => json_encode(['title' => 'From JSON', 'excerpt' => null, 'tags' => ['first'], 'author' => ['name' => 'Lina']]),
        ]);

        expect($code)->toBe(0)
            ->and(json_decode($output, true))->toBe([
                'title' => 'From a pair',
                'count' => 12,
                'draft' => false,
                'excerpt' => null,
                'tags' => ['first', 'second'],
                'author' => ['name' => 'Lina', 'age' => 41],
            ]);
    });

    it('keeps pair values as strings for the form-style coercion to convert', function () {
        [$code, $output] = runActionCommand(['name' => 'run-echo', 'input' => ['title=12', 'excerpt=', 'draft=1']]);

        expect($code)->toBe(0)
            ->and(json_decode($output, true))->toBe(['title' => '12', 'draft' => true, 'excerpt' => null]);
    });

    it('refuses a --input that is not a JSON object', function (string $json) {
        [$code, $output] = runActionCommand(['name' => 'run-echo', '--input' => $json]);

        expect($code)->toBe(1)->and($output)->toContain('The --input option must be a JSON object.');
    })->with(['a list' => '["title"]', 'a string' => '"title"', 'not JSON' => '{title: 1}']);

    it('refuses an argument that is not a key=value pair', function () {
        [$code, $output] = runActionCommand(['name' => 'run-echo', 'input' => ['title']]);

        expect($code)->toBe(1)->and($output)->toContain('The input [title] is not a key=value pair.');
    });
});

describe('refusals', function () {
    it('exits 2 with one line per invalid key', function () {
        [$code, $output] = runActionCommand(['name' => 'create-note', 'input' => ['title='.str_repeat('x', 21)], '--as' => $this->as]);

        expect($code)->toBe(2)
            ->and($output)->toBe(implode("\n", [
                'title: The title field must not be greater than 20 characters.',
                'body: The body field is required.',
            ])."\n")
            ->and(Post::query()->count())->toBe(0);
    });

    it('exits 1 with the fixed sentence when the action is not found for this context', function () {
        [$code, $output] = runActionCommand(['name' => 'hidden-note', '--as' => $this->as]);

        expect($code)->toBe(1)->and($output)->toBe(trans('agentic-actions::http.not_found')."\n");
    });

    it('says when the call ran without a user because --as was left out', function () {
        [$code, $output] = runActionCommand(['name' => 'hidden-note']);

        expect($code)->toBe(1)->and($output)->toBe(trans('agentic-actions::http.not_found')."\nNo --as was given, so the action ran without a user.\n");
    });

    it('prints the fixed sentence in the --locale given', function () {
        [$code, $output] = runActionCommand(['name' => 'hidden-note', '--as' => $this->as, '--locale' => 'ar']);

        expect($code)->toBe(1)->and($output)->toBe(trans('agentic-actions::http.not_found', [], 'ar')."\n");
    });

    it('exits 1 with the translated message for a refusal', function () {
        [$code, $output] = runActionCommand(['name' => 'refusing-note', '--as' => $this->as]);

        expect($code)->toBe(1)->and($output)->toBe("This note cannot be saved.\n");
    });

    it('exits 4 with the class and message of a crash, reported once', function () {
        Exceptions::fake();

        [$code, $output] = runActionCommand(['name' => 'crashing-note', '--as' => $this->as]);

        expect($code)->toBe(4)->and($output)->toBe("RuntimeException: The note crashed.\n");

        Exceptions::assertReported(RuntimeException::class);
        Exceptions::assertReportedCount(1);
    });
});

describe('stops before the action runs', function () {
    it('exits 1 for an unknown name', function () {
        [$code, $output] = runActionCommand(['name' => 'nothing-here']);

        expect($code)->toBe(1)->and($output)->toContain('No action named [nothing-here]. Run php artisan actions:list.');
    });

    it('exits 1 for an unknown user', function () {
        [$code, $output] = runActionCommand(['name' => 'create-note', 'input' => ['title=Hi', 'body=x'], '--as' => '999']);

        expect($code)->toBe(1)
            ->and($output)->toContain('No user [999].')
            ->and(Post::query()->count())->toBe(0);
    });

    it('exits 1 for --tenant without a tenant model', function () {
        [$code, $output] = runActionCommand(['name' => 'team-note', 'input' => ['title=Hi'], '--as' => $this->as, '--tenant' => 'acme']);

        expect($code)->toBe(1)->and($output)->toContain('The --tenant option needs a tenant model');
    });

    it('exits 1 for an unknown tenant', function () {
        $this->useTeamTenancy();

        [$code, $output] = runActionCommand(['name' => 'team-note', 'input' => ['title=Hi'], '--as' => $this->as, '--tenant' => 'nope']);

        expect($code)->toBe(1)->and($output)->toContain('No tenant [nope].');
    });
});

describe('context', function () {
    it('resolves --tenant by the tenant model\'s route key', function () {
        $this->useTeamTenancy();

        $team = Team::factory()->create(['slug' => 'acme']);
        $team->users()->attach($this->user);

        [$code, $output] = runActionCommand(['name' => 'team-note', 'input' => ['title=Hi'], '--as' => $this->as, '--tenant' => 'acme']);

        expect($code)->toBe(0)
            ->and($output)->toBe("{}\n")
            ->and(Post::query()->sole()->team_id)->toBe($team->getKey());
    });

    it('takes an integer --as and --tenant, as a test passes them to $this->artisan()', function () {
        $this->useTeamTenancy();

        $team = Team::factory()->create(['slug' => '42']);
        $team->users()->attach($this->user);

        $this->artisan('actions:run', ['name' => 'team-note', 'input' => ['title=Hi'], '--as' => $this->user->getKey(), '--tenant' => 42])
            ->doesntExpectOutputToContain('No --as was given')
            ->assertExitCode(0);

        expect(Post::query()->sole()->only(['user_id', 'team_id']))->toBe(['user_id' => $this->user->getKey(), 'team_id' => $team->getKey()]);
    });

    it('takes an integer --key', function () {
        [$code, $output] = runActionCommand(['name' => 'keyed-note', '--as' => $this->user->getKey(), '--key' => 7]);

        expect($code)->toBe(0)
            ->and(json_decode($output, true))->toBe(['key' => ActionContext::console($this->user, null, 'en', '7')->forAction('keyed-note')->requireIdempotencyKey()]);
    });

    it('feeds --key to requireIdempotencyKey(), and refuses with 428 without it', function () {
        [$code, $output] = runActionCommand(['name' => 'keyed-note', '--as' => $this->as, '--key' => 'abc']);

        $expected = ActionContext::console($this->user, null, 'en', 'abc')->forAction('keyed-note')->requireIdempotencyKey();

        [$missingCode, $missing] = runActionCommand(['name' => 'keyed-note', '--as' => $this->as]);

        expect($code)->toBe(0)
            ->and(json_decode($output, true))->toBe(['key' => $expected])
            ->and($missingCode)->toBe(1)
            ->and($missing)->toBe(trans('agentic-actions::http.idempotency_required')."\n");
    });
});

describe('tables', function () {
    it('prints a table: the labels as headers, raw values, and a last line when it was cut', function () {
        config(['agentic-actions.discovery.classes' => [PostStats::class], 'agentic-actions.views.max_rows' => 2]);
        $this->refreshActions();

        foreach (['Launch notes' => 'one two three', 'Roadmap' => 'one', 'Draft' => 'one two three four'] as $title => $body) {
            Post::factory()->for($this->user)->create(['title' => $title, 'body' => $body]);
        }

        [$code, $output] = runActionCommand(['name' => 'post-stats', '--as' => $this->as]);

        expect($code)->toBe(0)->and($output)->toBe(<<<'TXT'
            +--------------+-------+-------+--------+
            | Title        | Words | Share | Author |
            +--------------+-------+-------+--------+
            | Launch notes | 3     | 0.375 |        |
            | Roadmap      | 1     | 0.125 |        |
            +--------------+-------+-------+--------+
            Showing the first 2 rows

            TXT);
    });

    it('prints a dataset\'s caption above its table, so the dates the call resolved to are on screen', function () {
        config(['agentic-actions.discovery.classes' => [PostNumbers::class]]);
        $this->refreshActions();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
        Post::factory()->for($this->user)->count(2)->create(['created_at' => '2026-09-10 09:00:00']);

        [$code, $output] = runActionCommand(['name' => 'post-numbers', '--as' => $this->as, '--input' => '{"measures": ["posts"], "since": "-1m"}']);

        expect($code)->toBe(0)->and($output)->toBe(<<<'TXT'
            Posts · 1 Sep 2026 – 30 Sep 2026
            +-------+
            | Posts |
            +-------+
            | 2     |
            +-------+

            TXT);
    });

    it('prints each cell as text: the console\'s tags stay text, and no control character reaches the terminal', function () {
        config(['agentic-actions.discovery.classes' => [PostStats::class]]);
        $this->refreshActions();
        Post::factory()->for($this->user)->create(['title' => "<href=https://example.com>Launch</>\e]0;Title\x07 notes", 'body' => 'one']);

        [$code, $output] = runActionCommand(['name' => 'post-stats', '--as' => $this->as]);

        expect($code)->toBe(0)
            ->and($output)->toContain('<href=https://example.com>Launch</>]0;Title notes')
            ->and($output)->not->toContain("\e")
            ->and($output)->not->toContain("\x07");
    });
});
