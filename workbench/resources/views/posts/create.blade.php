<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>New post</title>
</head>
<body>
    @if (session('action.name') === 'create-post')
        <p>Saved "{{ session('action.output.title') }}" as a draft.</p>
    @endif

    <form method="POST" action="{{ route('actions.create-post') }}">
        @csrf

        <label for="title">Title</label>
        <input id="title" name="title" value="{{ old('title') }}">
        @error('title')
            <p class="error">{{ $message }}</p>
        @enderror

        <label for="body">Body</label>
        <textarea id="body" name="body">{{ old('body') }}</textarea>
        @error('body')
            <p class="error">{{ $message }}</p>
        @enderror

        <label for="card_number">Card number</label>
        <input id="card_number" name="card_number" value="{{ old('card_number') }}" autocomplete="off">

        <button type="submit">Save draft</button>
    </form>
</body>
</html>
