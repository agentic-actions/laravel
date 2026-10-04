<?php

use AgenticActions\Facades\Actions;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Providers\Tools\ToolSearch;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\Ai\CreateNoteTool;
use Tests\Fixtures\Ai\LeakyTool;
use Tests\Fixtures\Ai\MixedAgent;
use Tests\Fixtures\Ai\NotesAgent;
use Tests\Fixtures\Ai\NoteWriter;
use Tests\Fixtures\Ai\SearchMixedAgent;
use Tests\Fixtures\Ai\SubAgentMixedAgent;
use Tests\Fixtures\Ai\SupportAgent;
use Tests\Fixtures\Streaming\PageAgent;
use Tests\Fixtures\Streaming\StreamAgent;
use Workbench\App\Models\User;

/*
 * Actions::assertAgentTools(): one agent's tools, resolved as laravel/ai resolves them before a turn, have unique
 * names, offer no forbidden key and stay within agents.max_tools_per_toolset, and an agent carrying
 * #[WithPageContext] returns the PageContext middleware.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config(['agentic-actions.discovery.paths' => [
        ...config('agentic-actions.discovery.paths'),
        dirname(__DIR__, 2).'/Fixtures/Ai',
    ]]);

    $this->refreshActions();

    Auth::shouldUse('web');

    $this->user = User::factory()->create();
});

it('passes for an agent whose tools are all action tools', function () {
    Actions::assertAgentTools(new NotesAgent($this->user));
    Actions::assertAgentTools(new SupportAgent($this->user));
});

it('passes for an agent with no tools at all', function () {
    Actions::assertAgentTools(new NoteWriter);
});

it('fails for a hand-written tool named like an action tool', function () {
    expect(fn () => Actions::assertAgentTools(new MixedAgent($this->user)))
        ->toThrow(AssertionFailedError::class, MixedAgent::class.' has more than one tool named [create-note].');
});

it('fails for a sub-agent that laravel/ai names like an action tool', function () {
    expect(fn () => Actions::assertAgentTools(new SubAgentMixedAgent($this->user)))
        ->toThrow(AssertionFailedError::class, 'more than one tool named [create-note]');
});

it('fails for a duplicate inside a tool-search group', function () {
    expect(fn () => Actions::assertAgentTools(new SearchMixedAgent($this->user)))
        ->toThrow(AssertionFailedError::class, 'more than one tool named [create-note]');
});

it('names every duplicated name once', function () {
    $agent = new AnonymousAgent('Help.', [], [new CreateNoteTool, new NoteWriter, new ToolSearch([new CreateNoteTool, new LeakyTool, new LeakyTool])]);

    expect(fn () => Actions::assertAgentTools($agent))
        ->toThrow(AssertionFailedError::class, 'has more than one tool named [create-note, LeakyTool].');
});

it('fails for a hand-written tool that offers a forbidden key, at any depth', function () {
    $agent = new AnonymousAgent('Help.', [], [new LeakyTool]);

    expect(fn () => Actions::assertAgentTools($agent))
        ->toThrow(AssertionFailedError::class, 'offers input keys an agent may never be offered: [LeakyTool: account.client_secret]');
});

it('fails when a toolset holds more action tools than the limit', function () {
    config(['agentic-actions.agents.max_tools_per_toolset' => 1]);

    expect(fn () => Actions::assertAgentTools(new NotesAgent($this->user)))
        ->toThrow(AssertionFailedError::class, NotesAgent::class.' receives more than agents.max_tools_per_toolset (1) action tools in [default (6)].');
});

it('counts action tools per toolset, not across toolsets', function () {
    config(['agentic-actions.agents.max_tools_per_toolset' => 2]);

    Actions::assertAgentTools(new SupportAgent($this->user));

    expect(fn () => Actions::assertAgentTools(new NotesAgent($this->user)))->toThrow(AssertionFailedError::class, 'default (6)');
});

describe('the page context', function () {
    beforeEach(function () {
        config(['agentic-actions.discovery.paths' => [
            ...config('agentic-actions.discovery.paths'),
            dirname(__DIR__, 2).'/Fixtures/Streaming',
        ]]);

        $this->refreshActions();
    });

    it('passes for an agent carrying #[WithPageContext] whose middleware() returns actionMiddleware()', function () {
        Actions::assertAgentTools(new PageAgent($this->user));
    });

    it('fails for an agent carrying #[WithPageContext] whose middleware() leaves it out', function () {
        expect(fn () => Actions::assertAgentTools(new PageAgent($this->user, wired: false)))
            ->toThrow(AssertionFailedError::class, PageAgent::class.' carries #[WithPageContext], but middleware() does not return $this->actionMiddleware(), so the page never reaches the model.');
    });

    it('asks nothing of an agent without the attribute', function () {
        Actions::assertAgentTools(new StreamAgent($this->user, wired: false));
        Actions::assertAgentTools(new NotesAgent($this->user));
    });
});
