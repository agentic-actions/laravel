<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->skipUnlessAi();
    $this->reviewRoot = 'discovery-review-'.getmypid();
    File::copyDirectory(dirname(__DIR__, 2).'/Fixtures/Discovery/Groups/Core', base_path($this->reviewRoot.'/src'));
    config(['agentic-actions.discovery.groups' => [
        'core' => ['paths' => [$this->reviewRoot.'/src'], 'snapshot' => $this->reviewRoot.'/a.json', 'typescript' => $this->reviewRoot.'/a.ts'],
    ]]);
    $this->refreshActions();
});

afterEach(function () {
    if (isset($this->reviewRoot)) {
        File::deleteDirectory(base_path($this->reviewRoot));
    }
});

it('refuses an output that is a symbolic link, so a generated file never overwrites what it points to', function (string $key, string $command, string $extension) {
    $target = base_path($this->reviewRoot.'/protected.php');
    $sentinel = '<?php // application source';
    File::put($target, $sentinel);
    $output = $this->reviewRoot.'/linked'.$extension;
    symlink($target, base_path($output));
    config(["agentic-actions.discovery.groups.core.{$key}" => $output]);

    $this->artisan($command)->expectsOutputToContain('is a symbolic link')->assertFailed();

    expect(File::get($target))->toBe($sentinel);
})->with([
    'snapshot' => ['snapshot', 'actions:check --update', '.json'],
    'typescript' => ['typescript', 'actions:typescript', '.ts'],
]);
