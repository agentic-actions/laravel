<?php

use AgenticActions\ActionContext;
use AgenticActions\Ai\ActionTool;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Facades\Actions;
use AgenticActions\Streaming\ActionsProtocol;
use AgenticActions\Streaming\Activity;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Streaming\HelperAgent;
use Tests\Fixtures\Streaming\LinkedNote;
use Tests\Fixtures\Streaming\OneStepGateway;
use Tests\Fixtures\Streaming\Parts;
use Tests\Fixtures\Streaming\SaveNote;
use Tests\Fixtures\Streaming\StreamAgent;
use Tests\Fixtures\Streaming\ThrowingTool;
use Tests\Fixtures\Streaming\TracedTool;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * The relay: one data-action row per tool of the streamed run, written as each tool starts and ends, with the status
 * the tool's recorded outcome gives, and nothing for any other run.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'agentic-actions.discovery.paths' => [...config('agentic-actions.discovery.paths'), dirname(__DIR__, 2).'/Fixtures/Streaming'],
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();

    Auth::shouldUse('web');
    Trace::reset();

    SaveNote::$throwingLabel = false;
    LinkedNote::$redirect = null;
    TracedTool::$mode = 'ok';
    ThrowingTool::$validation = false;

    $this->user = User::factory()->create();

    // Stream one turn of the tool calls in one provider step, then a reply, and read what reached the browser.
    $this->turn = function (array $toolCalls, ?StreamAgent $agent = null): array {
        (new OneStepGateway($toolCalls, 'Replied.'))->fake(StreamAgent::class);

        return Parts::of(Parts::body(($agent ?? new StreamAgent($this->user))->stream('Go.')));
    };

    // What the model heard from each tool, in order.
    $this->heard = [];

    Event::listen(ToolInvoked::class, function (ToolInvoked $event): void {
        $this->heard[] = (string) $event->result;
    });
});

afterEach(function () {
    ignore_user_abort(false);
});

it('writes each row as its own tool finishes, before any text or finish, even in one provider step', function () {
    (new OneStepGateway([
        new ToolCall('call_1', 'slow-note', ['call' => 'one']),
        new ToolCall('call_2', 'slow-note', ['call' => 'two']),
    ], 'Both saved.'))->fake(StreamAgent::class);

    $response = (new StreamAgent($this->user))->stream('Go.')->usingProtocol(new ActionsProtocol)->toResponse(request());

    // Every chunk that leaves PHP's buffer is logged beside the tools' own entries, in the order it happened.
    ob_start(function (string $chunk): string {
        foreach ($chunk === '' ? [] : Parts::of($chunk) as $part) {
            Trace::record(match (true) {
                $part === '[DONE]' => '[DONE]',
                $part['type'] === 'data-action' => 'row '.$part['data']['status'],
                default => $part['type'],
            });
        }

        return '';
    });

    $response->sendContent();

    ob_end_clean();

    $relevant = array_values(array_filter(Trace::$calls, fn (string $entry): bool => str_starts_with($entry, 'row ')
        || str_starts_with($entry, 'start ') || str_starts_with($entry, 'end ') || in_array($entry, ['text-start', 'finish'], true)));

    expect($relevant)->toBe([
        'row running', 'start one', 'end one', 'row done',
        'row running', 'start two', 'end two', 'row done',
        'text-start', 'finish',
    ]);
});

describe('statuses', function () {
    it('gives one row per tool, with the status the outcome gives', function () {
        Exceptions::fake();

        $rows = Parts::rows(($this->turn)([
            new ToolCall('call_1', 'save-note', ['title' => 'Hi']),
            new ToolCall('call_2', 'save-note', ['title' => str_repeat('x', 30)]),
            new ToolCall('call_3', 'outcome-note', ['mode' => 'deny']),
            new ToolCall('call_4', 'outcome-note', ['mode' => 'refuse']),
            new ToolCall('call_5', 'outcome-note', ['mode' => 'crash']),
        ]));

        $post = Post::query()->sole();

        expect($rows)->toBe([
            ['action' => 'save-note', 'label' => 'Note saved', 'status' => 'done', 'effect' => 'write', 'touches' => ['notes'], 'link' => ['url' => "/notes/{$post->id}", 'follow' => true]],
            ['action' => 'save-note', 'label' => 'Saving your note…', 'status' => 'refused', 'effect' => 'write', 'note' => 'Not done'],
            ['action' => 'outcome-note', 'label' => 'Saving…', 'status' => 'refused', 'effect' => 'write', 'note' => 'Not done'],
            ['action' => 'outcome-note', 'label' => 'Saving…', 'status' => 'refused', 'effect' => 'write', 'note' => 'Not done'],
            ['action' => 'outcome-note', 'label' => 'Saving…', 'status' => 'failed', 'effect' => 'write', 'note' => 'Couldn\'t finish'],
        ]);
    });

    it('gives a refused row for an action the door does not know for this agent', function () {
        $agent = (new StreamAgent($this->user))->withTools([
            new ActionTool(ClassExposure::of(SaveNote::class), ActionContext::agent($this->user), ['another-toolset']),
        ]);

        $rows = Parts::rows(($this->turn)([new ToolCall('call_1', 'save-note', ['title' => 'Hi'])], $agent));

        expect($rows)->toBe([['action' => 'save-note', 'label' => 'Saving your note…', 'status' => 'refused', 'effect' => 'write', 'note' => 'Not done']])
            ->and($this->heard)->toBe(['Not done: that action is not available here. Do not try it again.'])
            ->and(Post::query()->count())->toBe(0);
    });
});

describe('labels', function () {
    it('labels an action without its own labels by its effect, in the person\'s language', function (string $locale, array $labels) {
        app()->setLocale($locale);
        LinkedNote::$redirect = '/notes';

        $rows = Parts::rows(($this->turn)([
            new ToolCall('call_1', 'read-notes', []),
            new ToolCall('call_2', 'linked-note', []),
            new ToolCall('call_3', 'save-note', ['title' => str_repeat('x', 30)]),
        ]));

        expect(array_map(fn (array $row): array => [$row['label'], $row['note'] ?? null], $rows))->toBe($labels);
    })->with([
        'English' => ['en', [['Looked it up', null], ['Saved', null], ['Saving your note…', 'Not done']]],
        'Arabic' => ['ar', [['انتهى البحث', null], ['تم الحفظ', null], ['Saving your note…', 'لم يتم']]],
    ]);

    it('reads both labels before their own call, so no row shows what its own call did', function () {
        $saved = Parts::rows(($this->turn)([
            new ToolCall('call_1', 'save-note', ['title' => 'First']),
            new ToolCall('call_2', 'save-note', ['title' => 'Second']),
        ]));

        $thought = Parts::rows(($this->turn)([
            new ToolCall('call_1', 'StatefulTool', ['word' => 'alpha']),
            new ToolCall('call_2', 'StatefulTool', ['word' => 'beta']),
        ]));

        expect(array_column($saved, 'label'))->toBe(['Note saved', 'Note saved'])
            ->and(Post::query()->pluck('title')->all())->toBe(['First', 'Second'])
            ->and($thought[0]['label'])->not->toContain('alpha')
            ->and($thought[1]['label'])->not->toContain('beta');
    });

    it('shows no row for a label that throws, and keeps the write and the model\'s sentence', function () {
        Exceptions::fake();
        SaveNote::$throwingLabel = true;

        $parts = ($this->turn)([new ToolCall('call_1', 'save-note', ['title' => 'Hi'])]);

        expect(Parts::rows($parts))->toBe([])
            ->and(Post::query()->pluck('title')->all())->toBe(['Hi'])
            ->and($this->heard)->toBe(['Done.'])
            ->and(array_slice(Parts::types($parts), -3))->toBe(['finish-step', 'finish', '[DONE]'])
            ->and(Parts::types($parts))->not->toContain('error');

        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The label failed.');
    });
});

describe('touches and links', function () {
    it('sends ["*"] for a Write that names no touches, and none for a Read', function () {
        $rows = Parts::rows(($this->turn)([
            new ToolCall('call_1', 'linked-note', []),
            new ToolCall('call_2', 'read-notes', []),
        ]));

        expect($rows[0]['touches'])->toBe(['*'])
            ->and($rows[1])->not->toHaveKey('touches');
    });

    it('sends a same-host link with $followLink', function (string $url) {
        LinkedNote::$redirect = str_replace('{host}', (string) parse_url((string) config('app.url'), PHP_URL_HOST), $url);

        $rows = Parts::rows(($this->turn)([new ToolCall('call_1', 'linked-note', [])]));

        expect($rows[0]['link'])->toBe(['url' => LinkedNote::$redirect, 'follow' => false]);
    })->with([
        'a path' => ['/posts/1'],
        'this app\'s host' => ['https://{host}/x'],
    ]);

    it('sends no link that could leave the site or reach another route', function (string $url) {
        LinkedNote::$redirect = $url;

        $rows = Parts::rows(($this->turn)([new ToolCall('call_1', 'linked-note', [])]));

        expect($rows[0]['status'])->toBe('done')
            ->and($rows[0])->not->toHaveKey('link');
    })->with([
        'protocol-relative' => ['//evil.example'],
        'a backslash' => ['/\\evil.example'],
        'javascript' => ['javascript:alert(1)'],
        'another host' => ['https://other.example/x'],
        'a dot-dot segment' => ['/posts/../x'],
        'an encoded dot-dot segment' => ['/posts/%2e%2e/x'],
        'a dot segment' => ['/posts/./x'],
        'a control character' => ["/posts/\x01x"],
        'empty' => [''],
    ]);

    it('reports a throwing redirectTo() and sends no link', function () {
        Exceptions::fake();
        LinkedNote::$redirect = new RuntimeException('The link failed.');

        $rows = Parts::rows(($this->turn)([new ToolCall('call_1', 'linked-note', [])]));

        expect($rows[0]['status'])->toBe('done')
            ->and($rows[0])->not->toHaveKey('link');

        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The link failed.');
    });

    it('keeps the write and the success reply when redirectTo() and the reporter both throw', function () {
        LinkedNote::$redirect = new RuntimeException('The link failed.');

        $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $exception): never {
            throw new RuntimeException('The reporter failed.');
        });

        $parts = ($this->turn)([new ToolCall('call_1', 'linked-note', [])]);

        expect(Post::query()->pluck('title')->all())->toBe(['Linked'])
            ->and($this->heard)->toBe(['Done.'])
            ->and(Parts::rows($parts)[0]['status'])->toBe('ended')
            ->and(array_slice(Parts::types($parts), -3))->toBe(['finish-step', 'finish', '[DONE]'])
            ->and(Parts::types($parts))->not->toContain('error');
    });
});

describe('hand-written tools', function () {
    it('shows what the tool reported, the worst report winning, and ended when it reported nothing', function (string $mode, array $row) {
        TracedTool::$mode = $mode;

        $rows = Parts::rows(($this->turn)([new ToolCall('call_1', 'TracedTool', [])]));

        expect($rows)->toBe([['action' => 'TracedTool', ...$row]]);
    })->with([
        'ok' => ['ok', ['label' => 'Traced', 'status' => 'done', 'touches' => ['notes']]],
        'refused' => ['refused', ['label' => 'Tracing…', 'status' => 'refused', 'note' => 'Not done']],
        'crashed' => ['crashed', ['label' => 'Tracing…', 'status' => 'failed', 'note' => 'Couldn\'t finish']],
        'twice' => ['twice', ['label' => 'Tracing…', 'status' => 'refused', 'note' => 'Not done']],
        'nothing' => ['nothing', ['label' => 'Tracing…', 'status' => 'ended']],
    ]);

    it('shows no row for a tool without DescribesActivity', function () {
        $parts = ($this->turn)([new ToolCall('call_1', 'SilentTool', [])]);

        expect(Parts::rows($parts))->toBe([])
            ->and(Trace::$calls)->toBe(['SilentTool'])
            ->and(Parts::types($parts))->toContain('finish');
    });

    it('shows failed for a tool that throws', function () {
        Exceptions::fake();

        $rows = Parts::rows(($this->turn)([new ToolCall('call_1', 'ThrowingTool', [])]));

        expect($rows)->toBe([['action' => 'ThrowingTool', 'label' => 'Checking…', 'status' => 'failed', 'note' => 'Couldn\'t finish']]);
    });

    it('shows ended for a ValidationException, and the model gets its messages and carries on', function () {
        ThrowingTool::$validation = true;

        $parts = ($this->turn)([new ToolCall('call_1', 'ThrowingTool', [])]);

        expect(Parts::rows($parts))->toBe([['action' => 'ThrowingTool', 'label' => 'Checking…', 'status' => 'ended']])
            ->and($this->heard)->toBe(['The word must be longer.'])
            ->and(array_slice(Parts::types($parts), -3))->toBe(['finish-step', 'finish', '[DONE]']);
    });
});

describe('other runs', function () {
    it('writes nothing for a prompt() run', function () {
        (new OneStepGateway([new ToolCall('call_1', 'TracedTool', [])]))->fake(StreamAgent::class);

        ob_start();
        (new StreamAgent($this->user))->prompt('Go.');
        $written = ob_get_clean();

        expect($written)->toBe('')
            ->and(Trace::$calls)->toBe(['TracedTool ignore_user_abort=0']);
    });

    it('writes nothing for a queued run', function () {
        (new OneStepGateway([new ToolCall('call_1', 'TracedTool', [])]))->fake(StreamAgent::class);

        ob_start();
        (new StreamAgent($this->user))->queue('Go.');
        $written = ob_get_clean();

        expect($written)->toBe('')
            ->and(Trace::$calls)->toBe(['TracedTool ignore_user_abort=0']);
    });

    it('writes nothing for a sub-agent\'s tools inside the turn', function () {
        HelperAgent::fake([new ToolCall('call_9', 'TracedTool', []), 'Helped.']);

        $agent = (new StreamAgent($this->user))->withTools(fn (array $tools): array => [...$tools, new HelperAgent]);

        $parts = ($this->turn)([new ToolCall('call_1', 'HelperAgent', ['task' => 'Help.'])], $agent);

        expect(Parts::rows($parts))->toBe([])
            ->and(Trace::$calls)->toBe(['TracedTool ignore_user_abort=1']);
    });

    it('does nothing outside a stream, and never throws', function () {
        $outcome = Actions::call(['stream'], 'save-note', ['title' => 'Hi'], ActionContext::agent($this->user));

        ob_start();
        Activity::record(new Request([], 'call_1', 'inv_1'), true, ['notes']);
        Activity::record(new Request([], 'call_1'), false, crashed: true);
        Activity::outcome(new Request([], 'call_1', 'inv_1'), $outcome);
        $written = ob_get_clean();

        expect($written)->toBe('')
            ->and(ActionsProtocol::current())->toBeNull();
    });
});
