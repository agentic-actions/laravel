<?php

use AgenticActions\Ask;

/*
 * Ask: what a form says beyond schema(). Every setter returns the instance, a later choice or default for a field
 * replaces the earlier one, confirm() and textarea() add, and toArray() has the shape Form::build() reads.
 */

it('replaces a field\'s choices and default, and adds confirmed fields and widgets', function () {
    $ask = (new Ask)
        ->message('First.')
        ->message('A few details for your post.')
        ->confirm('title')
        ->confirm('status', 'title')
        ->choices('status', ['draft' => 'Draft'])
        ->choices('status', ['draft' => 'Draft', 'published' => 'Published'])
        ->default('status', 'draft')
        ->default('status', 'published')
        ->default('owner', 5)
        ->textarea('body')
        ->textarea('excerpt', 'body');

    expect($ask->toArray())->toBe([
        'message' => 'A few details for your post.',
        'confirm' => ['title', 'status'],
        'choices' => ['status' => ['draft' => 'Draft', 'published' => 'Published']],
        'defaults' => ['status' => 'published', 'owner' => 5],
        'widgets' => ['body' => 'textarea', 'excerpt' => 'textarea'],
    ]);
});
