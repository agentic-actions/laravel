<?php

it('fails to load a class that also uses a trait defining run(), __invoke() or dispatch()', function (string $trait, string $method) {
    $script = tempnam(sys_get_temp_dir(), 'agentic-actions-final').'.php';

    // PHP stops at the first final method a class overrides, so each method gets its own class and process.
    file_put_contents($script, '<?php require '.var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).'; require '.var_export(__DIR__.'/Fixtures/traits-defining-final-methods.php', true).'; final class MixesTraitIntoAction extends AgenticActions\Action { use Tests\Unit\Core\Fixtures\\'.$trait.'; } echo "loaded";');

    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1', $output, $status);

    unlink($script);

    expect($status)->not->toBe(0)
        ->and(implode("\n", $output))->toContain('Cannot override final method AgenticActions\Action::'.$method.'()')
        ->not->toContain('loaded');
})->with([
    'run()' => ['DefinesRun', 'run'],
    '__invoke()' => ['DefinesInvoke', '__invoke'],
    'dispatch()' => ['DefinesDispatch', 'dispatch'],
]);
