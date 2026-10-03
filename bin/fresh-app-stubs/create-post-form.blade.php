<div>
    @if ($saved)
        <p>Saved as a draft.</p>
    @endif

    <form wire:submit="save">
        <label for="title">Title</label>
        <input id="title" wire:model="title">
        @error('title')
            <p>{{ $message }}</p>
        @enderror

        <label for="body">Body</label>
        <textarea id="body" wire:model="body"></textarea>
        @error('body')
            <p>{{ $message }}</p>
        @enderror

        <button type="submit">Save draft</button>
    </form>
</div>
