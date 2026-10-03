<?php

use AgenticActions\ActionContext;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Outcome;
use Tests\Fixtures\Actions\ListNotes;
use Tests\Fixtures\Views\PostStats;

/*
 * What a model reads of a table the person sees: that they see it and how many rows it holds, and the hint; then as data
 * the caption and a compact copy of the first views.model_rows rows. Where it was called and when is the stream's test.
 */

beforeEach(function () {
    config(['agentic-actions.views.model_rows' => 2]);

    $this->output = [
        'columns' => [['key' => 'title', 'label' => 'Title', 'type' => 'text'], ['key' => 'words', 'label' => 'Words', 'type' => 'integer', 'description' => 'Words in the body']],
        'rows' => [['title' => str_repeat('a', 100), 'words' => 3], ['title' => 'Roadmap', 'words' => 1], ['title' => 'Draft', 'words' => 4]],
        'truncated' => true,
        'chart' => ['type' => 'bar', 'x' => 'title', 'y' => ['words']],
        'caption' => 'Posts by team',
    ];
});

it('says the person sees the table, then gives the first rows with each text cut to 80 characters', function (string $locale, string $lines) {
    $outcome = Outcome::completed(ClassExposure::of(PostStats::class), ActionContext::agent(null, locale: $locale), null, $this->output, null);

    expect($outcome->forModel(true))->toBe($lines."\n---\n".json_encode([
        'caption' => 'Posts by team',
        'columns' => [['key' => 'title', 'label' => 'Title'], ['key' => 'words', 'label' => 'Words', 'description' => 'Words in the body']],
        'rows' => [['title' => str_repeat('a', 79).'…', 'words' => 3], ['title' => 'Roadmap', 'words' => 1]],
        'truncated' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
})->with([
    'English' => ['en', "The person now sees this as a table of 3 rows.\nIt holds only the first 3 rows.\nSay in a sentence or two what stands out; do not repeat the rows."],
    'Arabic' => ['ar', "يرى الشخص الآن هذه البيانات في جدول، عدد صفوفه 3.\nلا يضم الجدول إلا أول 3 من الصفوف.\nقل في جملة أو جملتين ما يلفت النظر فيه، ولا تكرر الصفوف."],
]);

it('puts the action\'s own reply first, in place of the package\'s', function () {
    $whole = ['columns' => [], 'rows' => [], 'truncated' => false];

    $outcome = Outcome::completed(ClassExposure::of(PostStats::class), ActionContext::agent(null), null, $whole, 'Here are your posts.');

    expect($outcome->forModel(true))->toBe("Here are your posts.\nSay in a sentence or two what stands out; do not repeat the rows.\n---\n".json_encode($whole));
});

it('gives a Read that shows no table today\'s sentence, whatever the caller says', function () {
    $outcome = Outcome::completed(ClassExposure::of(ListNotes::class), ActionContext::agent(null), null, $this->output, null);

    expect($outcome->forModel(true))->toBe("Found.\n---\n".json_encode($this->output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
});
