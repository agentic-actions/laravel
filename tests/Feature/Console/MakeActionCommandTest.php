<?php

use AgenticActions\Console\Checks;
use AgenticActions\Console\Finding;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->actions = app_path('Actions');
    $this->stubs = base_path('stubs');
    $this->stub = dirname(__DIR__, 3).'/stubs/agentic-action.stub';

    File::deleteDirectory($this->actions);
    File::deleteDirectory($this->stubs);
});

afterEach(function () {
    File::deleteDirectory($this->actions);
    File::deleteDirectory($this->stubs);
});

it('writes app/Actions/{name}.php from the stub: no #[Expose], no effect, and an authorize() that denies', function () {
    $this->artisan('make:agentic-action', ['name' => 'CreatePost'])->assertSuccessful();

    $source = File::get(app_path('Actions/CreatePost.php'));

    expect($source)->toBe(str_replace(['{{ namespace }}', '{{ class }}'], ['App\Actions', 'CreatePost'], File::get($this->stub)))
        ->and($source)->not->toMatch('/^#\[/m')
        ->and($source)->toContain('protected ?Effect $effect = null;')
        ->and($source)->toContain("public function authorize(ActionContext \$context): bool\n    {\n        return false;\n    }");
});

it('refuses to overwrite an action unless --force is given', function () {
    $this->artisan('make:agentic-action', ['name' => 'CreatePost'])->assertSuccessful();

    File::put(app_path('Actions/CreatePost.php'), '<?php // edited');

    $this->artisan('make:agentic-action', ['name' => 'CreatePost'])->expectsOutputToContain('Action already exists.');

    expect(File::get(app_path('Actions/CreatePost.php')))->toBe('<?php // edited');

    $this->artisan('make:agentic-action', ['name' => 'CreatePost', '--force' => true])->assertSuccessful();

    expect(File::get(app_path('Actions/CreatePost.php')))->toContain('final class CreatePost extends Action');
});

it('publishes the stub, and a published stub wins', function () {
    $this->artisan('vendor:publish', ['--tag' => 'agentic-actions-stubs'])->assertSuccessful();

    $published = base_path('stubs/agentic-action.stub');

    expect(File::get($published))->toBe(File::get($this->stub));

    File::put($published, str_replace('Do the work.', 'Do the work, the app\'s way.', File::get($published)));

    $this->artisan('make:agentic-action', ['name' => 'Billing/SendInvoice'])->assertSuccessful();

    expect(File::get(app_path('Actions/Billing/SendInvoice.php')))
        ->toContain("namespace App\Actions\Billing;")
        ->toContain('final class SendInvoice extends Action')
        ->toContain('Do the work, the app\'s way.');
});

it('writes a class the scanner discovers, which actions:list shows exposed nowhere', function () {
    $this->artisan('make:agentic-action', ['name' => 'DraftReport'])->assertSuccessful();

    // The Testbench skeleton's App namespace is not autoloaded, so the test loads the generated file itself.
    require_once app_path('Actions/DraftReport.php');

    config(['agentic-actions.discovery.paths' => [$this->actions]]);

    Artisan::call('actions:list');
    $output = Artisan::output();

    $findings = array_map(
        fn (Finding $finding): string => "{$finding->row}: {$finding->message}",
        app(Checks::class)->run(),
    );

    expect($output)->toMatch('/^  draft-report \.+ App\\\\Actions\\\\DraftReport *$/m')
        ->toContain(
            "  effect     undeclared\n",
            "  web        skipped: no #[Expose]\n",
            "  agents     skipped: no #[Expose]\n",
            "  cli        php artisan actions:run draft-report\n",
            "  authorize  before input\n",
        )
        ->and($findings)->toContain('Effect: App\Actions\DraftReport: $effect is undeclared, so nothing remote reaches it. Declare Effect::Read, Effect::Write, Effect::Destructive or Effect::External.');
});
