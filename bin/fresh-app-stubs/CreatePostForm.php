<?php

namespace App\Livewire;

use AgenticActions\ActionContext;
use AgenticActions\Refusal;
use App\Actions\CreatePost;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class CreatePostForm extends Component
{
    public string $title = '';

    public string $body = '';

    public ?int $saved = null;

    /**
     * Draft the post through the action. Invalid input and refusals both become field errors.
     */
    public function save(): void
    {
        try {
            $post = CreatePost::run(['title' => $this->title, 'body' => $this->body], ActionContext::http(Auth::user()));
        } catch (Refusal $r) {
            throw $r->toValidationException();
        }

        $this->saved = $post->id;

        $this->reset('title', 'body');
    }

    /**
     * Render the form.
     */
    public function render(): View
    {
        return view('livewire.create-post-form');
    }
}
