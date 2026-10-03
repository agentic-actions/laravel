<?php

namespace Workbench\App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * The Blade form that posts to the generated create-post route.
 */
final class PostFormController
{
    /**
     * Show the form.
     */
    public function __invoke(): View
    {
        return view('posts.create');
    }
}
