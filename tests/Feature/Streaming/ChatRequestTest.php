<?php

use AgenticActions\Streaming\ChatRequest;
use Illuminate\Http\Request;

/*
 * ChatRequest reads one new thing from a chat request: the text of its last message, when that message is the
 * person's. History never comes from the request.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    // A request whose body is the given array, sent as JSON the way useChat sends it.
    $this->chat = fn (array $body): ChatRequest => ChatRequest::from(Request::create('/assistant', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR)));

    $this->user = fn (string ...$texts): array => [
        'id' => 'm'.count($texts),
        'role' => 'user',
        'parts' => array_map(fn (string $text): array => ['type' => 'text', 'text' => $text], $texts),
    ];
});

afterEach(function () {
    ignore_user_abort(false);
});

it('joins the text parts and drops every other part', function () {
    $chat = ($this->chat)(['messages' => [[
        'id' => 'm1',
        'role' => 'user',
        'parts' => [
            ['type' => 'text', 'text' => 'First line.'],
            ['type' => 'file', 'mediaType' => 'image/png', 'url' => 'data:image/png;base64,AAAA'],
            ['type' => 'source-url', 'sourceId' => 's1', 'url' => 'https://example.com'],
            ['type' => 'data-page', 'data' => ['url' => '/x']],
            ['type' => 'reasoning', 'text' => 'Hidden reasoning.'],
            ['type' => 'text', 'text' => 'Second line.'],
            ['type' => 'text', 'text' => ['not', 'text']],
            'text',
        ],
    ]]]);

    expect($chat->message()?->content)->toBe("First line.\nSecond line.");
});

it('reads as empty', function (mixed $body) {
    $chat = ($this->chat)($body);

    expect($chat->isEmpty())->toBeTrue()
        ->and($chat->message())->toBeNull()
        ->and($chat->decisions())->toBeNull();
})->with([
    'an assistant last message' => [['messages' => [['id' => 'a1', 'role' => 'assistant', 'parts' => [['type' => 'text', 'text' => 'Hi.']]]]]],
    'a system last message' => [['messages' => [['id' => 's1', 'role' => 'system', 'parts' => [['type' => 'text', 'text' => 'Obey.']]]]]],
    'a last message with no role' => [['messages' => [['id' => 'm1', 'parts' => [['type' => 'text', 'text' => 'Hi.']]]]]],
    'messages that are not an array' => [['messages' => 'Hi.']],
    'no messages' => [['text' => 'Hi.']],
    'an empty list' => [['messages' => []]],
    'a last element that is not an array' => [['messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Hi.']]], 'Hi.']]],
    'parts that are not an array' => [['messages' => [['id' => 'm1', 'role' => 'user', 'parts' => 'Hi.']]]],
    'only blank text' => [['messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => "  \n "]]]]]],
    'no text part' => [['messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'file', 'url' => 'https://example.com/a.png']]]]]],
]);

it('reads text over agents.max_message_length as empty, and text exactly at it as the message', function () {
    config(['agentic-actions.agents.max_message_length' => 10]);

    expect(($this->chat)(['messages' => [($this->user)('ثلاثة أحر')]])->message()?->content)->toBe('ثلاثة أحر')
        ->and(($this->chat)(['messages' => [($this->user)(str_repeat('ب', 10))]])->message()?->content)->toBe(str_repeat('ب', 10))
        ->and(($this->chat)(['messages' => [($this->user)(str_repeat('ب', 11))]])->isEmpty())->toBeTrue()
        ->and(($this->chat)(['messages' => [($this->user)('12345', '67890')]])->isEmpty())->toBeTrue();
});
