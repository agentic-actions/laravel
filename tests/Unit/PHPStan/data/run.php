<?php

// PHPStan analyses this file for RunReturnTypeTest; nothing runs it.

namespace Tests\Unit\PHPStan\Data;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Views\Rows;
use Tests\Fixtures\PHPStan\UntypedHandle;
use Workbench\App\Actions\CreatePost;
use Workbench\App\Actions\DeletePost;
use Workbench\App\Actions\Posts;
use Workbench\App\Actions\PostStats;
use Workbench\App\Models\Post;

use function PHPStan\Testing\assertType;

/**
 * @param  class-string<Action>  $any
 */
function calls(ActionContext $context, bool $create, string $any): void
{
    assertType(Post::class, CreatePost::run([], $context));
    assertType('null', DeletePost::run([], $context));
    assertType('Illuminate\Support\Collection<int, array{title: string, words: int, share: float}>', PostStats::run([], $context));
    assertType(Rows::class, Posts::run([], $context));

    $class = CreatePost::class;
    assertType(Post::class, $class::run([], $context));

    $either = $create ? CreatePost::class : DeletePost::class;
    assertType('Workbench\App\Models\Post|null', $either::run([], $context));

    assertType('mixed', UntypedHandle::run([], $context));
    assertType('mixed', $any::run([], $context));
}
