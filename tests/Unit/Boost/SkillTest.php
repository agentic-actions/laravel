<?php

use AgenticActions\Action;
use AgenticActions\Ask;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * The skill Laravel Boost offers to an app that requires the package.
 */
function boostSkill(): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/resources/boost/skills/agentic-actions-development/SKILL.md');
}

it('opens with the frontmatter Boost reads', function () {
    expect(preg_match('/\A---\nname: (.+)\ndescription: (.+)\n---\n/', boostSkill(), $frontmatter))->toBe(1)
        ->and($frontmatter[1])->toBe('agentic-actions-development')
        ->and(trim($frontmatter[2]))->not->toBe('');
});

it('names only classes the package has', function () {
    preg_match_all('/AgenticActions(?:\\\\[A-Z][A-Za-z0-9]*)+/', boostSkill(), $matches);

    // By file, not by autoloading: a class that implements a laravel/ai contract cannot load where laravel/ai is missing.
    $classes = array_values(array_unique($matches[0]));
    $missing = array_filter($classes, fn (string $class): bool => ! is_file(dirname(__DIR__, 3).'/src/'.str_replace('\\', '/', substr($class, strlen('AgenticActions\\'))).'.php'));

    expect($classes)->toContain('AgenticActions\Action', 'AgenticActions\Streaming\ActionsProtocol')
        ->and($missing)->toBe([]);
});

it('names only commands the package registers', function () {
    preg_match_all('/\b(?:actions:[a-z-]+|make:agentic-action)\b/', boostSkill(), $matches);

    // actions:read and actions:write are token abilities, not commands.
    $abilities = array_map(strval(...), (array) config('agentic-actions.abilities'));
    $commands = array_values(array_diff(array_unique($matches[0]), $abilities));

    expect($commands)->toContain('make:agentic-action', 'actions:check', 'actions:list')
        ->and(array_diff($commands, array_keys(Artisan::all())))->toBe([]);
});

it('names only config keys the package has', function () {
    preg_match_all('/\bagentic-actions(?:\.[a-z_]+)+/', boostSkill(), $matches);

    $keys = array_values(array_unique($matches[0]));

    expect($keys)->toContain('agentic-actions.mcp.path', 'agentic-actions.mcp.tenant_path')
        ->and(array_filter($keys, fn (string $key): bool => ! config()->has($key)))->toBe([]);
});

it('teaches a confirmation with the Action methods and config the package has', function () {
    $task = Str::after(boostSkill(), "\n## 5. ");

    preg_match_all('/\bfunction (approval[A-Za-z]*)\(/', $task, $methods);

    expect($task)->toStartWith('Let the copilot delete, after a confirmation')
        ->toContain("#[Expose(web: true, agents: ['default'])]", 'Effect::Destructive', 'ChatRequest::from($request, $agent)', '->respond(', 'messageId:', 'agent: $agent', 'approvalCard(', '<ApprovalCard')
        ->and($methods[1])->toBe(['approvalReason', 'approvalSummary'])
        ->and(array_filter($methods[1], fn (string $method): bool => ! method_exists(Action::class, $method)))->toBe([])
        ->and(config('agentic-actions.approvals.ttl'))->toBe(1800);
});

it('teaches asking the person with the Action members and Ask methods the package has', function () {
    $task = Str::after(boostSkill(), "\n## 6. ");

    preg_match_all('/->(message|confirm|choices|default|textarea)\(/', $task, $calls);

    expect($task)->toStartWith('Ask the person for what the model left out')
        ->toContain('protected bool $askForMissing = true;', 'public function ask(Ask $ask, ActionContext $context): Ask', 'AgenticActions\Ask', 'Effect::Write', 'ChatRequest::from($request, $agent)', 'elicitation(messages.at(-1))', '<ElicitationForm', 'answerElicitation(')
        ->and((new ReflectionClass(Action::class))->hasProperty('askForMissing'))->toBeTrue()
        ->and(method_exists(Action::class, 'ask'))->toBeTrue()
        ->and(array_values(array_unique($calls[1])))->toBe(['message', 'confirm', 'textarea', 'choices'])
        ->and(array_filter($calls[1], fn (string $method): bool => ! method_exists(Ask::class, $method)))->toBe([]);

    // The client's names, as the committed build declares them.
    $declared = fn (string $file): string => (string) file_get_contents(dirname(__DIR__, 3)."/js/dist/{$file}.d.ts");

    expect($declared('parts'))->toContain('export declare function elicitation(')
        ->and($declared('index'))->toMatch('/export \{[^}]*\belicitation,[^}]*\} from \'\.\/parts\.js\'/')
        ->and($declared('react'))->toContain('export declare function ElicitationForm(')
        ->and($declared('ai-sdk'))->toContain('export declare function answerElicitation(');
});
