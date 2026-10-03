<?php

namespace Tests\Feature\Http;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use AgenticActions\Events\ActionRefused;
use AgenticActions\Exceptions\ReadActionWrote;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Refusal;
use AgenticActions\Runner;
use AgenticActions\Surface;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\ValidatedInput;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\Actions\ReadThatWritesEarly;
use Tests\Fixtures\Actions\TracedNote;
use Tests\Fixtures\Queue\Queued;
use Throwable;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * What a JSON caller receives, for every kind of outcome. The responder renders successes and refusals; the app's own
 * exception handler renders everything else, with the package's fixed sentences.
 */

beforeEach(function () {
    TracedNote::reset();

    $this->user = User::factory()->create();

    $this->mountRoutes(function (): void {
        Route::middleware('auth')->group(fn () => Actions::routes());
        Route::middleware(['api', 'auth:sanctum'])->prefix('api')->name('api.')->group(fn () => Actions::routes());
    });
});

it('answers 200 with the projected output and no envelope', function () {
    $this->actingAs($this->user)
        ->postJson('/actions/create-note', ['title' => 'Hi', 'body' => 'Secret body', 'user_id' => 999])
        ->assertOk()
        ->assertExactJson(['id' => Post::query()->value('id'), 'title' => 'Hi']);

    expect(Post::query()->value('user_id'))->toBe($this->user->getKey());
});

it('answers {} when the action declares no output', function () {
    $post = Post::factory()->for($this->user)->create();

    $response = $this->actingAs($this->user)->postJson('/actions/publish-note', ['note' => $post->getKey()])->assertOk();

    expect($response->getContent())->toBe('{}')
        ->and($post->fresh()?->status)->toBe('published');
});

it('answers 422 with message and errors for invalid input', function () {
    $this->actingAs($this->user)
        ->postJson('/actions/create-note', ['title' => str_repeat('x', 30)])
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['title', 'body']])
        ->assertJsonPath('errors.title.0', 'The title field must not be greater than 20 characters.');
});

it('validates strict types after form-style coercion', function () {
    $own = Post::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->post('/actions/late-authorize', ['post_id' => (string) $own->getKey()], ['Accept' => 'application/json'])
        ->assertOk();

    foreach (["{$own->getKey()}.0", "{$own->getKey()}abc", "{$own->getKey()}e0"] as $invalid) {
        $this->actingAs($this->user)
            ->post('/actions/late-authorize', ['post_id' => $invalid], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['post_id']);
    }
});

it('answers a refusal with its status, code and details', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/actions/refusing-note')
        ->assertStatus(409)
        ->assertExactJson([
            'message' => 'This note cannot be saved.',
            'code' => 'This note cannot be saved.',
            'details' => ['reason' => 'x'],
        ]);

    expect($response->headers->get('Content-Type'))->toBe('application/json');
});

it('answers a refusal an input-free authorize() raises the same way, before the input is validated', function () {
    TracedNote::$authorizes = fn () => throw Refusal::make('Not today.')->status(423);

    $this->mountRoutes(fn () => Route::middleware('auth')->post('traced', TracedNote::class));

    $this->actingAs($this->user)
        ->postJson('/traced', ['title' => str_repeat('x', 30)])
        ->assertStatus(423)
        ->assertExactJson(['message' => 'Not today.', 'code' => 'Not today.', 'details' => []]);
});

it('answers a refusal on a field with 422 errors, through the app\'s handler', function () {
    $this->mountRoutes(fn () => Route::middleware('auth')->post('titled', TakenTitle::class));

    $this->actingAs($this->user)
        ->postJson('/titled', ['title' => 'Hi'])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'That title is taken.',
            'errors' => ['title' => ['That title is taken.']],
        ]);
});

it('answers 428 without an Idempotency-Key, and namespaces the key it is given', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/actions/keyed-note')
        ->assertStatus(428)
        ->assertExactJson([
            'message' => trans('agentic-actions::http.idempotency_required'),
            'code' => 'agentic-actions::http.idempotency_required',
            'details' => [],
        ]);

    expect($response->getContent())->toContain('"details":{}');

    $key = $this->actingAs($this->user)
        ->postJson('/actions/keyed-note', [], ['Idempotency-Key' => 'abc'])
        ->assertOk()
        ->json('key');

    expect($key)->toBeString()->and(Str::isUuid($key))->toBeTrue()->and($key)->not->toBe('abc');
});

it('answers 404 and 403 with the fixed sentences in the request\'s locale, never an exception\'s message', function () {
    TracedNote::$authorizes = false;

    $this->mountRoutes(fn () => Route::middleware(['auth', SpeaksArabic::class])->prefix('ar')->group(function (): void {
        Actions::routes();
        Route::post('traced', TracedNote::class);
        Route::post('missing', FindsMissingNote::class);
    }));

    $this->actingAs($this->user)->postJson('/ar/actions/hidden-note')->assertNotFound()->assertExactJson(['message' => 'غير موجود.']);
    $this->actingAs($this->user)->postJson('/ar/traced', ['title' => 'Hi'])->assertForbidden()->assertExactJson(['message' => 'لا يُسمح لك بهذا الإجراء.']);

    $response = $this->actingAs($this->user)->postJson('/ar/missing')->assertNotFound()->assertExactJson(['message' => 'غير موجود.']);

    expect($response->getContent())->not->toContain('17')->not->toContain('Post');
});

it('answers 422 for a JSON number too large to be finite, before handle() runs', function (string $body) {
    MeasuredNote::$handled = 0;

    $this->mountRoutes(fn () => Route::middleware('auth')->post('measure', MeasuredNote::class));

    $this->actingAs($this->user)
        ->call('POST', '/measure', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $body)
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'The size field must be a number.', 'errors' => ['size' => ['The size field must be a number.']]]);

    expect(MeasuredNote::$handled)->toBe(0);
})->with(['{"size":1e999}', '{"size":-1e999}']);

it('keeps a list\'s null items in their places', function () {
    $this->mountRoutes(fn () => Route::middleware('auth')->post('rows', ListedRows::class));

    $this->actingAs($this->user)
        ->postJson('/rows', ['rows' => [null, ['title' => 'A', 'extra' => 'x'], null]])
        ->assertOk()
        ->assertExactJson(['rows' => [null, ['title' => 'A'], null]]);
});

it('refuses an empty string for a file, and takes it as null for a nullable one, with or without ConvertEmptyStringsToNull', function (bool $skipped) {
    AttachedNote::$received = null;

    if ($skipped) {
        ConvertEmptyStringsToNull::skipWhen(fn (): bool => true);
    }

    $this->mountRoutes(fn () => Route::middleware('auth')->post('attach', AttachedNote::class));

    try {
        $this->actingAs($this->user)->postJson('/attach', ['photo' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo' => 'The photo field must be a file.']);

        expect(AttachedNote::$received)->toBeNull();

        $this->actingAs($this->user)->postJson('/attach', ['photo' => UploadedFile::fake()->create('a.txt'), 'cover' => ''])->assertOk();

        expect(AttachedNote::$received)->toHaveKey('cover', null);
    } finally {
        ConvertEmptyStringsToNull::flushState();
    }
})->with(['with the middleware' => false, 'with it skipped' => true]);

it('gives every surface the web\'s answer for an empty or blank string, with or without Laravel\'s input middleware', function (array $input) {
    $this->mountRoutes(fn () => Route::middleware('auth')->post('blank', BlankFields::class));

    $web = function () use ($input): array {
        BlankFields::$received = null;
        $response = $this->actingAs($this->user)->postJson('/blank', $input);

        return $response->isOk() ? ['ok' => BlankFields::$received] : ['invalid' => $response->json('errors')];
    };
    $answer = $web();

    TrimStrings::skipWhen(fn (): bool => true);
    ConvertEmptyStringsToNull::skipWhen(fn (): bool => true);

    try {
        expect($web())->toBe($answer);
    } finally {
        TrimStrings::flushState();
        ConvertEmptyStringsToNull::flushState();
    }

    $contexts = [
        ActionContext::system(),
        ActionContext::http($this->user),
        ActionContext::console($this->user, null, 'en', null),
        ActionContext::agent($this->user),
        ActionContext::queued(Surface::Http, $this->user, null, 'en', [], null, null),
        ActionContext::mcp(Queued::tokenUser(['actions:read'], $this->user), null),
    ];

    foreach ($contexts as $context) {
        BlankFields::$received = null;
        $outcome = app(Runner::class)->run(ClassExposure::of(BlankFields::class), $input, $context, Door::InProcess);

        expect($outcome->kind() === OutcomeKind::Ok ? ['ok' => BlankFields::$received] : ['invalid' => $outcome->errors()])->toBe($answer);
    }
})->with([
    'a string' => [['title' => '']],
    'a nullable string' => [['note' => '']],
    'a string enum' => [['status' => '']],
    'a string of at least one character' => [['code' => '']],
    'an email' => [['email' => '']],
    'a list item' => [['tags' => ['a', '']]],
    'an object\'s key' => [['meta' => ['label' => '']]],
    'a string of spaces' => [['title' => " \t "]],
    'a nullable string of spaces' => [['note' => '  ']],
    'an integer of spaces' => [['count' => '  ']],
    'a password' => [['password' => '']],
    'a password of spaces' => [['password' => '   ']],
    'nothing' => [[]],
]);

it('gives every surface, and the generated route\'s JSON and form posts, what a plain Laravel route with the same rules gives', function (array $input) {
    $this->mountRoutes(function (): void {
        Route::middleware('auth')->post('blank', BlankFields::class);
        Route::middleware('auth')->post('plain', function (Request $request): array {
            $rules = app(Runner::class)->rules(ClassExposure::of(BlankFields::class), ActionContext::http($request->user()));
            BlankFields::$received = Validator::make($request->all(), $rules)->validate();

            return [];
        });
    });

    $web = function (string $uri, bool $json) use ($input): array {
        BlankFields::$received = null;
        $response = $json
            ? $this->actingAs($this->user)->postJson($uri, $input)
            : $this->actingAs($this->user)->post($uri, $input, ['Accept' => 'application/json']);

        return $response->isOk() ? ['ok' => BlankFields::$received] : ['invalid' => $response->json('errors')];
    };
    $plain = $web('/plain', true);

    expect($web('/plain', false))->toBe($plain)
        ->and($web('/blank', true))->toBe($plain)
        ->and($web('/blank', false))->toBe($plain);

    $contexts = [
        ActionContext::system(),
        ActionContext::http($this->user),
        ActionContext::console($this->user, null, 'en', null),
        ActionContext::agent($this->user),
        ActionContext::queued(Surface::Http, $this->user, null, 'en', [], null, null),
        ActionContext::mcp(Queued::tokenUser(['actions:read'], $this->user), null),
    ];

    foreach ($contexts as $context) {
        BlankFields::$received = null;
        $outcome = app(Runner::class)->run(ClassExposure::of(BlankFields::class), $input, $context, Door::InProcess);

        expect($outcome->kind() === OutcomeKind::Ok ? ['ok' => BlankFields::$received] : ['invalid' => $outcome->errors()])->toBe($plain);
    }
})->with([
    'a key only rules() declares' => [['slug' => '']],
    'a key only rules() declares, of spaces' => [['slug' => '  ']],
    'a union rules() owns, an empty item' => [['topic' => ['a', '']]],
    'a list without items, an empty item' => [['labels' => ['a', '', ' ']]],
    'an object without properties, empty values' => [['extra' => ['k' => '', 'n' => ['m' => '  ']]]],
    'a list item of spaces' => [['tags' => ['a', ' ']]],
    'an empty password' => [['password' => '']],
    'a password of spaces' => [['password' => '   ']],
    'a password confirmation of spaces' => [['password_confirmation' => ' ']],
    'a current password of a tab' => [['current_password' => "\t"]],
    'a nested password of spaces' => [['account' => ['password' => '  ']]],
]);

it('keeps a list item none of whose declared keys was given in its place', function () {
    $this->mountRoutes(fn () => Route::middleware('auth')->post('items', ListedItems::class));

    $this->actingAs($this->user)
        ->postJson('/items', ['items' => [['extra' => 'x'], null, ['note' => 'A']]])
        ->assertOk()
        ->assertExactJson(['items' => [[], null, ['note' => 'A']]]);
});

it('hands a crash to the app\'s handler, which reports it once', function () {
    Exceptions::fake();

    $this->actingAs($this->user)
        ->postJson('/actions/crashing-note')
        ->assertServerError()
        ->assertExactJson(['message' => 'Server Error']);

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The note crashed.');
});

it('answers a Read with JSON even for a browser visit', function () {
    Post::factory()->for($this->user)->create(['title' => 'Mine']);

    $this->actingAs($this->user)
        ->post('/actions/list-notes')
        ->assertOk()
        ->assertExactJson(['posts' => [['id' => Post::query()->value('id'), 'title' => 'Mine']]]);
});

it('agrees with the handler\'s shouldRenderJsonWhen() on 200, 409 and 422', function () {
    $token = $this->user->createToken('client')->plainTextToken;

    // A plain form post, with no Accept: application/json, is a visit until the handler says otherwise.
    $this->withToken($token)->post('/api/actions/create-note', ['title' => 'Visit', 'body' => 'x'])->assertStatus(303);

    app(ExceptionHandler::class)->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*'));

    $this->withToken($token)->post('/api/actions/create-note', ['title' => 'Hi', 'body' => 'x'])
        ->assertOk()
        ->assertExactJson(['id' => Post::query()->where('title', 'Hi')->value('id'), 'title' => 'Hi']);

    $this->withToken($token)->post('/api/actions/refusing-note')
        ->assertStatus(409)
        ->assertJsonPath('code', 'This note cannot be saved.');

    $this->withToken($token)->post('/api/actions/create-note', ['title' => str_repeat('x', 30)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'body']);
});

it('keeps asking the app\'s handler while Exceptions::fake() wraps it', function () {
    app(ExceptionHandler::class)->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*'));

    Exceptions::fake();

    // Plain form posts, with no Accept: application/json: only the handler's rule makes the second one JSON.
    $this->actingAs($this->user)->post('/actions/create-note', ['title' => 'Visit', 'body' => 'x'])->assertStatus(303);

    $this->withToken($this->user->createToken('client')->plainTextToken)
        ->post('/api/actions/create-note', ['title' => 'Hi', 'body' => 'x'])
        ->assertOk()
        ->assertExactJson(['id' => Post::query()->where('title', 'Hi')->value('id'), 'title' => 'Hi']);
});

it('falls back to expectsJson() for a handler that is not Laravel\'s', function () {
    app()->instance(ExceptionHandler::class, new PlainHandler);

    $this->actingAs($this->user)->postJson('/actions/create-note', ['title' => 'Json', 'body' => 'x'])
        ->assertOk()
        ->assertExactJson(['id' => Post::query()->where('title', 'Json')->value('id'), 'title' => 'Json']);

    // An Inertia visit is a visit, whatever it accepts.
    $this->actingAs($this->user)
        ->postJson('/actions/create-note', ['title' => 'Inertia', 'body' => 'x'], ['X-Inertia' => 'true'])
        ->assertStatus(303);

    // Only the Accept header decides, so a form post to the API is a visit too.
    $this->withToken($this->user->createToken('client')->plainTextToken)
        ->post('/api/actions/create-note', ['title' => 'Form', 'body' => 'x'])
        ->assertStatus(303);
});

it('refuses a Read that writes while FormRequest resolves it, and changes nothing', function () {
    Exceptions::fake();

    $this->mountRoutes(fn () => Route::middleware('auth')->post('read-early', ReadThatWritesEarly::class));

    $this->actingAs($this->user)->postJson('/read-early')->assertServerError();

    Exceptions::assertReported(ReadActionWrote::class);

    expect(Post::query()->count())->toBe(0);
});

it('lets an ActionRefused listener write while a Read\'s HTTP resolution is guarded', function () {
    Event::listen(ActionRefused::class, function (ActionRefused $event): void {
        User::query()->firstOrFail()->posts()->create(['title' => "Refused {$event->action}", 'body' => 'audit', 'status' => 'draft']);
    });

    // The token grants writes only, so the Read stops at the token check, inside the guard.
    $token = $this->user->createToken('client', ['actions:write'])->plainTextToken;

    $this->withToken($token)->postJson('/api/actions/list-notes')->assertNotFound();

    expect(Post::query()->pluck('title')->all())->toBe(['Refused list-notes']);
});

/**
 * Refuses on the title field.
 */
final class TakenTitle extends Action
{
    protected ?Effect $effect = Effect::Write;

    /**
     * The title.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['title' => $schema->string()->required()];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Refuse the title.
     */
    public function handle(ActionContext $context, ValidatedInput $input): never
    {
        throw Refusal::make('That title is taken.')->on('title');
    }
}

/**
 * Takes one number and hands it back, counting each run of handle().
 */
final class MeasuredNote extends Action
{
    public static int $handled = 0;

    protected ?Effect $effect = Effect::Write;

    /**
     * One number.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['size' => $schema->number()->required()];
    }

    /**
     * The same number.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['size' => $schema->number()->required()];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Count the run and hand the input back.
     *
     * @return array<string, mixed>
     */
    public function handle(ValidatedInput $input): array
    {
        self::$handled++;

        return $input->all();
    }
}

/**
 * Takes a file and an optional one, and records what handle() received.
 */
final class AttachedNote extends Action
{
    /**
     * @var array<string, mixed>|null
     */
    public static ?array $received = null;

    protected ?Effect $effect = Effect::Write;

    /**
     * A file, and one that may be null.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['photo' => $schema->string()->format('binary'), 'cover' => $schema->string()->format('binary')->nullable()];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Record the input.
     *
     * @return array<string, mixed>
     */
    public function handle(ValidatedInput $input): array
    {
        self::$received = $input->all();

        return [];
    }
}

/**
 * Optional string fields of each kind, in a list and in an object, and an integer, recording what handle() received.
 */
final class BlankFields extends Action
{
    /**
     * @var array<string, mixed>|null
     */
    public static ?array $received = null;

    protected ?Effect $effect = Effect::Read;

    /**
     * A plain string, a nullable one, an enum, a bounded one, an email, a list, an object, an integer, the three keys
     * TrimStrings leaves alone and one nested, a list without items, an object without properties and a union.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string(),
            'note' => $schema->string()->nullable(),
            'status' => $schema->string()->enum(['draft', 'published']),
            'code' => $schema->string()->min(1),
            'email' => $schema->string()->format('email'),
            'tags' => $schema->array()->items($schema->string()),
            'meta' => $schema->object(['label' => $schema->string()]),
            'count' => $schema->integer(),
            'password' => $schema->string()->nullable(),
            'password_confirmation' => $schema->string()->nullable(),
            'current_password' => $schema->string()->nullable(),
            'account' => $schema->object(['password' => $schema->string()->nullable()]),
            'labels' => $schema->array(),
            'extra' => $schema->object(),
            'topic' => $schema->union(['string', 'array']),
        ];
    }

    /**
     * The union's rules, and a key only these rules declare.
     *
     * @return array<string, mixed>
     */
    public function rules(ActionContext $context): array
    {
        return [
            'topic' => ['sometimes'],
            'topic.*' => ['string'],
            'slug' => ['sometimes', 'string'],
        ];
    }

    /**
     * Anyone.
     */
    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    /**
     * Record the input.
     *
     * @return array<string, mixed>
     */
    public function handle(ValidatedInput $input): array
    {
        self::$received = $input->all();

        return [];
    }
}

/**
 * Takes a list of objects whose one key is optional, and hands it back in the order handle() received it.
 */
final class ListedItems extends Action
{
    protected ?Effect $effect = Effect::Write;

    /**
     * Items that may be null or empty.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['items' => $schema->array()->items($schema->object(['note' => $schema->string()])->nullable())->required()];
    }

    /**
     * The same items.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->schema($schema);
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * The input as handle() received it.
     *
     * @return array<string, mixed>
     */
    public function handle(ValidatedInput $input): array
    {
        return $input->all();
    }
}

/**
 * Takes a list whose items may be null and hands it back in the order handle() received it.
 */
final class ListedRows extends Action
{
    protected ?Effect $effect = Effect::Write;

    /**
     * Rows that may be null.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['rows' => $schema->array()->items($schema->object(['title' => $schema->string()->required()])->nullable())->required()];
    }

    /**
     * The same rows.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->schema($schema);
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * The input as handle() received it.
     *
     * @return array<string, mixed>
     */
    public function handle(ValidatedInput $input): array
    {
        return $input->all();
    }
}

/**
 * Looks up a note that does not exist, so the ModelNotFoundException names the model and the id.
 */
final class FindsMissingNote extends Action
{
    protected ?Effect $effect = Effect::Write;

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Find note 17.
     */
    public function handle(ActionContext $context): Post
    {
        return $context->find(Post::class, 17);
    }
}

/**
 * Sets the locale the way an app's own middleware does, before the action resolves.
 */
final class SpeaksArabic
{
    /**
     * Switch the application to Arabic for this request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        app()->setLocale('ar');

        return $next($request);
    }
}

/**
 * An exception handler that is not Laravel's own.
 */
final class PlainHandler implements ExceptionHandler
{
    public function report(Throwable $e): void {}

    public function shouldReport(Throwable $e): bool
    {
        return true;
    }

    public function render($request, Throwable $e): Response
    {
        return new Response('', 500);
    }

    public function renderForConsole($output, Throwable $e): void {}
}
