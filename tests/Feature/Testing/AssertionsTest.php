<?php

use AgenticActions\Facades\Actions;
use AgenticActions\Support\PackageStatus;
use AgenticActions\Testing\ActionAssertions;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Fixtures\Actions\CreateNote;

uses(ActionAssertions::class);

/**
 * The toolset the shared fixtures fill once laravel/ai is installed: every bare #[Expose] Read or Write.
 *
 * @return list<string>
 */
function defaultToolset(): array
{
    return ['create-note', 'hidden-note', 'late-authorize', 'list-notes', 'team-note', 'translated-note'];
}

it('passes for the exact list, in any order', function () {
    $this->skipUnlessAi();

    $this->assertToolset('default', defaultToolset());
    $this->assertToolset('default', array_reverse(defaultToolset()));
    Actions::assertToolset('default', defaultToolset());
});

it('passes for an empty toolset', function () {
    $this->assertToolset('support', []);
    Actions::assertToolset('support', []);
});

it('fails naming the extra and the missing actions', function () {
    $this->skipUnlessAi();

    $names = array_values(array_diff(defaultToolset(), ['list-notes']));

    expect(fn () => $this->assertToolset('default', [...$names, 'publish-post']))
        ->toThrow(ExpectationFailedException::class, 'The [default] toolset does not hold exactly the expected actions. Extra: list-notes. Missing: publish-post.');
});

it('fails naming the actions an empty toolset was expected to hold', function () {
    expect(fn () => Actions::assertToolset('support', ['create-note']))
        ->toThrow(ExpectationFailedException::class, 'The [support] toolset does not hold exactly the expected actions. Extra: none. Missing: create-note.');
});

it('reads the toolsets the classes declare now', function () {
    $this->skipUnlessAi();

    $this->usePackages(['laravel/ai' => PackageStatus::Missing]);

    // Without laravel/ai the agent surface is skipped, so no class joins a toolset.
    $this->assertToolset('default', []);

    expect(fn () => $this->assertToolset('default', defaultToolset()))
        ->toThrow(ExpectationFailedException::class, 'Extra: none. Missing: '.implode(', ', defaultToolset()).'.');
});

it('fails toContainActionTool for a value that holds no action tool, naming what it holds', function () {
    expect(fn () => expect([])->toContainActionTool('create-note'))
        ->toThrow(ExpectationFailedException::class, 'No action tool [create-note] among: ')
        ->and(fn () => expect([new CreateNote, 'create-note'])->toContainActionTool('create-note'))
        ->toThrow(ExpectationFailedException::class, 'No action tool [create-note] among: ')
        ->and(fn () => expect('create-note')->toContainActionTool('create-note'))
        ->toThrow(ExpectationFailedException::class, 'No action tool [create-note] among: ');
});
