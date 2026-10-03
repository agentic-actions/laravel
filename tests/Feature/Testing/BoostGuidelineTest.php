<?php

use Illuminate\Support\Facades\Blade;

/**
 * The guideline file Laravel Boost offers to an app that requires the package.
 */
function boostGuidelinePath(): string
{
    return dirname(__DIR__, 3).'/resources/boost/guidelines/core.blade.php';
}

it('renders through Blade with its code untouched', function () {
    $rendered = Blade::render((string) file_get_contents(boostGuidelinePath()));

    expect($rendered)
        ->toContain('# Agentic Actions for Laravel')
        ->toContain("#[Expose]\nfinal class CreatePost extends Action")
        ->toContain('return $context->actor(User::class)->posts()->create($input->all());')
        ->toContain("#[Expose(agents: ['support'])]")
        ->toContain('php artisan actions:check --update')
        ->not->toContain('@verbatim')
        ->not->toContain('@endverbatim');
});

it('stays short enough to load into every prompt', function () {
    expect(count(file(boostGuidelinePath()) ?: []))->toBeLessThanOrEqual(50);
});
