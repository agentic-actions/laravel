<?php

use AgenticActions\Action;
use AgenticActions\Effect;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\Support\PackageStatus;
use AgenticActions\Surface;
use Tests\Fixtures\Actions\ArchiveNoteAction;
use Tests\Fixtures\Actions\ChildNote;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\DeleteNote;
use Tests\Fixtures\Actions\PublishNote;
use Tests\Fixtures\Actions\SyncActionItems;
use Tests\Fixtures\Actions\TeamNote;
use Tests\Fixtures\Actions\TracedNote;
use Tests\Fixtures\Misconfigured\DestructiveForAgents;

beforeEach(fn () => TracedNote::reset());

it('reads default properties without running a constructor', function () {
    $entry = Entry::fromClass(TracedNote::class);

    expect(TracedNote::$instances)->toBe(0)
        ->and($entry->name)->toBe('traced-note')
        ->and($entry->effect)->toBe(Effect::Write);
});

it('removes only a trailing "Action" from the default name', function () {
    expect(Entry::fromClass(ArchiveNoteAction::class)->name)->toBe('archive-note')
        ->and(Entry::fromClass(SyncActionItems::class)->name)->toBe('sync-action-items');
});

it('derives the default name from the class basename, with a run of capitals as one word', function (string $basename, string $name) {
    // One empty action per row, each in a namespace of its own: PHP reads class names without case, so ExportCSV and
    // ExportCsv in one namespace would be one class.
    $namespace = 'Tests\\Fixtures\\Names\\N'.md5($basename);
    $class = "{$namespace}\\{$basename}";

    if (! class_exists($class)) {
        eval("namespace {$namespace}; final class {$basename} extends \\AgenticActions\\Action {}");
    }

    expect(Entry::fromClass($class)->name)->toBe($name);
})->with([
    'a run of capitals that starts it' => ['CSVExport', 'csv-export'],
    'a run that ends it' => ['ExportCSV', 'export-csv'],
    'the same word written in lower case, which collides with it' => ['ExportCsv', 'export-csv'],
    'a run inside it' => ['ImportCSVFile', 'import-csv-file'],
    'two runs' => ['HTMLToPDF', 'html-to-pdf'],
    'a run of two that ends it' => ['GetUserID', 'get-user-id'],
    'a run of two before a word' => ['ABTest', 'ab-test'],
    'a run before a digit' => ['HTTP2Push', 'http2-push'],
    'a run before one trailing "Action"' => ['ImportCSVFileAction', 'import-csv-file'],
    'a lower-case first letter, which joins the run' => ['eIDCheck', 'eid-check'],
    'a one-letter word, kept apart' => ['CreateAPost', 'create-a-post'],
    'OAuth, split as a one-letter word is' => ['SyncOAuthToken', 'sync-o-auth-token'],
    'a word with a capital inside, which still splits' => ['SyncDevOpsBoard', 'sync-dev-ops-board'],
    'that word written as one' => ['SyncDevopsBoard', 'sync-devops-board'],
    'a digit after a word' => ['UploadToS3', 'upload-to-s3'],
    'a plural run, which splits before its last capital' => ['SendSMSs', 'send-sm-ss'],
    'only "Action"' => ['Action', 'action'],
]);

it('keeps the route segment of a set name as Str::kebab() reads it, whatever derives the default names', function () {
    $entry = Entry::fromClass((new class extends Action
    {
        protected string $name = 'sendSMS';
    })::class);

    expect($entry->name)->toBe('sendSMS')
        ->and($entry->segment())->toBe('send-s-m-s');
});

it('does not inherit #[Expose]', function () {
    $entry = Entry::fromClass(ChildNote::class);

    expect($entry->surfaces)->toBe([Surface::Console])
        ->and($entry->skipped['http'])->toBe('no #[Expose]')
        ->and(Entry::fromClass(CreateNote::class)->allows(Surface::Http))->toBeTrue();
});

it('records toolsets only when the agent surface is open', function () {
    $this->skipUnlessAi();
    $this->usePackages(['laravel/ai' => PackageStatus::Installed]);

    expect(Entry::fromClass(CreateNote::class)->toolsets)->toBe(['default'])
        ->and(Entry::fromClass(PublishNote::class)->toolsets)->toBe([])
        ->and(Entry::fromClass(DeleteNote::class)->toolsets)->toBe([])
        ->and(Entry::fromClass(DestructiveForAgents::class)->toolsets)->toBe(['x']);

    $this->usePackages(['laravel/ai' => PackageStatus::Missing]);

    expect(Entry::fromClass(CreateNote::class)->toolsets)->toBe([]);
});

it('is not tenant-scoped while tenant.model is null', function () {
    expect(Entry::fromClass(TeamNote::class)->tenantScoped)->toBeFalse();

    $this->useTeamTenancy();

    expect(Entry::fromClass(TeamNote::class)->tenantScoped)->toBeTrue()
        ->and(ClassExposure::of(TeamNote::class)->tenantScoped)->toBeTrue();
});

it('round-trips the manifest row', function () {
    $this->usePackages(['laravel/ai' => PackageStatus::Missing]);

    $entry = Entry::fromClass(CreateNote::class);
    $row = $entry->toManifest();

    expect($row)->toBe([
        'class' => CreateNote::class,
        'name' => 'create-note',
        'description' => 'Create a note for the signed-in author.',
        'effect' => 'write',
        'idempotent' => false,
        'tenant_scoped' => false,
        'touches' => [],
        'guests' => false,
        'validation_messages_to_model' => false,
        'error_bag' => 'default',
        'toolsets' => [],
        'has_agent_schema' => false,
        'surfaces' => ['console', 'http', 'mcp'],
        'skipped' => ['agent' => 'laravel/ai is not installed'],
        'errors' => [],
        'follow_link' => false,
        'ask_for_missing' => false,
    ])->and(Entry::fromManifest($row))->toEqual($entry);

    $followed = Entry::fromClass((new class extends Action
    {
        protected bool $followLink = true;
    })::class);

    expect($followed->toManifest()['follow_link'])->toBeTrue()
        ->and(Entry::fromManifest($followed->toManifest()))->toEqual($followed);
});

it('builds the snapshot row', function () {
    $this->skipUnlessAi();
    $this->usePackages(['laravel/ai' => PackageStatus::Installed]);

    expect(Entry::fromClass(CreateNote::class)->toSnapshot())->toBe([
        'class' => CreateNote::class,
        'effect' => 'write',
        'web' => true,
        'agents' => ['default'],
        'mcp' => true,
        'tenant_scoped' => false,
    ]);
});

it('refuses a class that is not a concrete action', function () {
    Entry::fromClass(stdClass::class);
})->throws(InvalidArgumentException::class);
