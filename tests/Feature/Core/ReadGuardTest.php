<?php

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\ActionsManager;
use AgenticActions\Effect;
use AgenticActions\Events\ActionCompleted;
use AgenticActions\Events\ActionRefused;
use AgenticActions\Exceptions\ReadActionWrote;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Security\ReadGuard;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Database\Connectors\MariaDbConnector;
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Database\Connectors\SqlServerConnector;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\ListNotes;
use Tests\Fixtures\Actions\ReadThatCaches;
use Tests\Fixtures\Actions\ReadThatWrites;
use Tests\Fixtures\Actions\ReadThatWritesEarly;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->context = ActionContext::http($this->user);
});

it('refuses a write in a Read\'s handle() before the statement runs', function () {
    Exceptions::fake();

    expect(fn () => ReadThatWrites::run([], $this->context))->toThrow(ReadActionWrote::class, 'A Read action tried to write [posts]. The statement did not run: give the action a writing effect, or list the table in agentic-actions.reads.writable_tables.')
        ->and(Post::query()->count())->toBe(0);

    $outcome = app(ActionsManager::class)->attempt(ReadThatWrites::class, [], $this->context);

    expect($outcome->kind())->toBe(OutcomeKind::Failed)
        ->and($outcome->exception())->toBeInstanceOf(ReadActionWrote::class)
        ->and(Post::query()->count())->toBe(0);

    Exceptions::assertReportedCount(1);
});

it('refuses a write in prepareForValidation(): the guard spans steps 3 to 9', function () {
    expect(fn () => ReadThatWritesEarly::run([], $this->context))->toThrow(ReadActionWrote::class)
        ->and(Post::query()->count())->toBe(0);
});

it('lets the package\'s event listeners write during a Read', function () {
    Event::listen(ActionCompleted::class, fn () => Post::factory()->for($this->user)->create(['title' => 'From a listener']));
    Event::listen(ActionRefused::class, fn () => Post::factory()->for($this->user)->create(['title' => 'From a refusal listener']));

    ListNotes::run([], $this->context);
    app(ActionsManager::class)->attempt(ListNotes::class, [], ActionContext::agent(null));

    expect(Post::query()->pluck('title')->all())->toBe(['From a listener', 'From a refusal listener']);
});

it('lets a Read cache on the database store with no setting', function () {
    expect(ReadThatCaches::run([], $this->context))->toBe('cached')
        ->and(DB::table('cache')->count())->toBe(1);
});

it('lets a Read open a transaction, and refuses a raw rollback', function () {
    expect(TransactionalRead::run([], $this->context))->toBe(0)
        ->and(fn () => RollbackRead::run([], $this->context))->toThrow(ReadActionWrote::class, 'A Read action tried to write [a statement]. The statement did not run: give the action a writing effect.');
});

it('refuses a batch sent through unprepared()', function () {
    expect(fn () => BatchRead::run([], $this->context))->toThrow(ReadActionWrote::class);
});

it('keeps a Read guarded after a nested Read returns', function () {
    expect(fn () => NestingRead::run([], $this->context))->toThrow(ReadActionWrote::class)
        ->and(Post::query()->count())->toBe(0);
});

it('keeps a Write called inside a Read guarded', function () {
    expect(fn () => WritingInsideRead::run([], $this->context))->toThrow(ReadActionWrote::class)
        ->and(Post::query()->count())->toBe(0);
});

it('is off outside Reads', function () {
    CreateNote::run(['title' => 'Hi', 'body' => 'x'], $this->context);
    Post::factory()->create();

    expect(Post::query()->count())->toBe(2);
});

it('is off when reads.guard is false', function () {
    config(['agentic-actions.reads.guard' => false]);

    ReadThatWrites::run([], $this->context);

    expect(Post::query()->count())->toBe(1);
});

it('allows writes to tables listed in reads.writable_tables', function () {
    config(['agentic-actions.reads.writable_tables' => ['posts']]);

    ReadThatWrites::run([], $this->context);

    expect(Post::query()->count())->toBe(1);
});

it('restores the previous depth after run() and unguarded(), also after an exception', function () {
    $guard = app(ReadGuard::class);
    $write = fn () => DB::table('posts')->delete();

    expect(fn () => $guard->run(fn () => $guard->unguarded(fn () => throw new RuntimeException('Inside.'))))->toThrow(RuntimeException::class);

    $write();

    expect(fn () => $guard->run(function () use ($guard, $write): void {
        $guard->unguarded($write);
        $write();
    }))->toThrow(ReadActionWrote::class);
});

it('guards every connection opened after boot', function () {
    config(['database.connections.second' => config('database.connections.'.config('database.default'))]);

    $guard = app(ReadGuard::class);

    expect(fn () => $guard->run(fn () => DB::connection('second')->statement('create table guarded_later (id integer)')))
        ->toThrow(ReadActionWrote::class, '[guarded_later]');
});

it('opens a SQLite connection inside a Read: the pragmas Laravel sends while connecting go straight to PDO', function () {
    config(['database.connections.opened_in_read' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => 5000,
        'journal_mode' => 'wal',
        'synchronous' => 'normal',
        'pragmas' => ['cache_size' => -4000],
    ]]);

    $guard = app(ReadGuard::class);

    expect($guard->run(fn () => DB::connection('opened_in_read')->scalar('pragma foreign_keys')))->toBe(1);

    $connection = DB::connection('opened_in_read');

    expect($connection->scalar('pragma busy_timeout'))->toBe(5000)
        ->and($connection->scalar('pragma synchronous'))->toBe(1)
        ->and($connection->scalar('pragma cache_size'))->toBe(-4000);

    foreach (['pragma foreign_keys = 0', 'pragma busy_timeout = 0', 'pragma journal_mode = delete', 'pragma synchronous = full', 'pragma cache_size = 1'] as $pragma) {
        expect(fn () => $guard->run(fn () => $connection->statement($pragma)))->toThrow(ReadActionWrote::class);
    }

    expect($connection->scalar('pragma foreign_keys'))->toBe(1)
        ->and($connection->scalar('pragma cache_size'))->toBe(-4000);
});

it('opens a server connection inside a Read: the SET statements Laravel sends while connecting go straight to PDO', function (string $driver, array $config, array $sent, array $refused) {
    /** @var ArrayObject<int, string> $received */
    $received = new ArrayObject;

    app()->bind("db.connector.{$driver}", fn (): ConnectorInterface => recordingConnector($driver, $received));
    config(['database.connections.opened_in_read' => [
        'driver' => $driver,
        'host' => '127.0.0.1',
        'database' => 'app',
        'username' => 'app',
        'password' => '',
        'prefix' => '',
        ...$config,
    ]]);

    $guard = app(ReadGuard::class);

    expect($guard->run(fn () => DB::connection('opened_in_read')->scalar('select 1')))->toBe(1)
        ->and($received->getArrayCopy())->toBe([...$sent, 'select 1']);

    foreach ($refused as $statement) {
        expect(fn () => $guard->run(fn () => DB::connection('opened_in_read')->statement($statement)))->toThrow(ReadActionWrote::class);
    }

    expect($received->getArrayCopy())->toBe([...$sent, 'select 1']);
})->with([
    'MySQL' => [
        'mysql',
        ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'timezone' => '+00:00', 'modes' => ['STRICT_TRANS_TABLES', 'NO_ENGINE_SUBSTITUTION'], 'isolation_level' => 'READ COMMITTED'],
        $mysql = [
            'use `app`;',
            'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;',
            "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci', time_zone='+00:00', SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';",
        ],
        $mysql,
    ],
    'MariaDB' => [
        'mariadb',
        ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_uca1400_ai_ci', 'strict' => false],
        $mariadb = [
            'use `app`;',
            "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_uca1400_ai_ci', SESSION sql_mode='NO_ENGINE_SUBSTITUTION';",
        ],
        $mariadb,
    ],
    'Postgres' => [
        'pgsql',
        ['charset' => 'utf8', 'search_path' => 'public', 'timezone' => 'UTC', 'isolation_level' => 'read committed', 'synchronous_commit' => 'off'],
        $pgsql = [
            'set session characteristics as transaction isolation level read committed',
            "set time zone 'UTC'",
            'set search_path to "public"',
            "set synchronous_commit to 'off'",
        ],
        $pgsql,
    ],
    'SQL Server, whose one statement only sets the transaction\'s isolation level' => [
        'sqlsrv',
        ['isolation_level' => 'READ COMMITTED'],
        ['SET TRANSACTION ISOLATION LEVEL READ COMMITTED'],
        [],
    ],
]);

it('leaves a connection unguarded when a test first uses it after Event::fake(), as docs/testing.md says', function () {
    config(['database.connections.second' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    Event::fake();

    app(ReadGuard::class)->run(fn () => DB::connection('second')->statement('create table unguarded (id integer)'));

    expect(DB::connection('second')->getSchemaBuilder()->hasTable('unguarded'))->toBeTrue();
});

it('guards a connection used after faking events, the ways docs/testing.md shows', function (Closure $fake) {
    config(['database.connections.second' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    $fake();

    expect(fn () => app(ReadGuard::class)->run(fn () => DB::connection('second')->statement('create table guarded_faked (id integer)')))
        ->toThrow(ReadActionWrote::class, '[guarded_faked]');
})->with([
    'the connection used before faking' => [function (): void {
        DB::connection('second');
        Event::fake();
    }],
    'fakeExcept() keeping the connection event' => [fn () => Event::fakeExcept([ConnectionEstablished::class])],
    'fake() naming only the events the test asserts on' => [fn () => Event::fake([ActionCompleted::class])],
]);

it('reads a statement on SQLite as SQLite does, so a one-backslash LIKE escape runs', function () {
    config(['database.connections.second' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    $connection = DB::connection('second');
    $connection->statement('create table notes (title text)');
    $connection->table('notes')->insert([['title' => '50% off'], ['title' => '50 items']]);

    $count = app(ReadGuard::class)->run(fn () => $connection->scalar("select count(*) from notes where title like ? escape '\\'", ['50\\%%']));

    expect($count)->toBe(1)
        ->and(fn () => app(ReadGuard::class)->run(fn () => $connection->statement("delete from notes where title like ? escape '\\'", ['50\\%%'])))
        ->toThrow(ReadActionWrote::class, 'A Read action tried to write [notes].');
});

it('says when it could not check a statement, apart from a write', function () {
    config(['database.connections.second' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    expect(fn () => app(ReadGuard::class)->run(fn () => DB::connection('second')->select("select 'never closed")))
        ->toThrow(ReadActionWrote::class, "A Read action sent a statement the Read guard could not check: [select 'never closed]. A quote or comment in it does not close");
});

it('refuses a write on the configured database, whatever its driver', function () {
    Exceptions::fake();

    $outcome = app(ActionsManager::class)->attempt(ReadThatWrites::class, [], $this->context);

    expect($outcome->exception())->toBeInstanceOf(ReadActionWrote::class)
        ->and(Post::query()->count())->toBe(0)
        ->and(ReadThatCaches::run([], $this->context))->toBe('cached')
        ->and(TransactionalRead::run([], $this->context))->toBe(0);
})->group('database');

/**
 * A Read that opens a transaction, which Laravel begins and commits through PDO.
 */
final class TransactionalRead extends Action
{
    protected ?Effect $effect = Effect::Read;

    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    public function handle(): int
    {
        return DB::transaction(fn (): int => Post::query()->count());
    }
}

/**
 * A Read that sends a raw ROLLBACK, which could discard an enclosing Write's work.
 */
final class RollbackRead extends Action
{
    protected ?Effect $effect = Effect::Read;

    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    public function handle(): bool
    {
        return DB::statement('rollback');
    }
}

/**
 * A Read that sends two statements at once.
 */
final class BatchRead extends Action
{
    protected ?Effect $effect = Effect::Read;

    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    public function handle(): bool
    {
        return DB::unprepared('select 1; delete from posts');
    }
}

/**
 * A Read that runs another Read, then writes.
 */
final class NestingRead extends Action
{
    protected ?Effect $effect = Effect::Read;

    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    public function handle(ActionContext $context): mixed
    {
        ListNotes::run([], $context);

        return Post::factory()->for($context->actor(User::class))->create();
    }
}

/**
 * A Read that runs a Write.
 */
final class WritingInsideRead extends Action
{
    protected ?Effect $effect = Effect::Read;

    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    public function handle(ActionContext $context): mixed
    {
        return CreateNote::run(['title' => 'Inside', 'body' => 'x'], $context);
    }
}

/**
 * A connector for one server driver that opens an in-memory SQLite PDO in place of the server's, so a test can open a
 * MySQL, MariaDB, Postgres or SQL Server connection and see every statement the connector sends while it connects.
 *
 * @param  ArrayObject<int, string>  $received
 */
function recordingConnector(string $driver, ArrayObject $received): ConnectorInterface
{
    return match ($driver) {
        'mysql' => new class($received) extends MySqlConnector
        {
            use OpensRecordingPdo;
        },
        'mariadb' => new class($received) extends MariaDbConnector
        {
            use OpensRecordingPdo;
        },
        'pgsql' => new class($received) extends PostgresConnector
        {
            use OpensRecordingPdo;
        },
        'sqlsrv' => new class($received) extends SqlServerConnector
        {
            use OpensRecordingPdo;
        },
    };
}

/**
 * Opens a RecordingPdo where a connector would open the server's PDO; the connector's own code runs unchanged.
 */
trait OpensRecordingPdo
{
    /**
     * @param  ArrayObject<int, string>  $received
     */
    public function __construct(private ArrayObject $received) {}

    /**
     * Open the recording PDO in place of the server's.
     *
     * @param  string  $dsn
     * @param  string|null  $username
     * @param  string|null  $password
     * @param  array<int, mixed>  $options
     */
    protected function createPdoConnection($dsn, $username, #[SensitiveParameter] $password, $options): PDO
    {
        return new RecordingPdo($this->received);
    }
}

/**
 * An in-memory SQLite PDO that records every statement it receives. A server's USE and SET statements are recorded
 * and answered without running, since SQLite has neither.
 */
final class RecordingPdo extends PDO
{
    /**
     * @param  ArrayObject<int, string>  $received
     */
    public function __construct(private ArrayObject $received)
    {
        parent::__construct('sqlite::memory:');
    }

    /**
     * Record a statement sent without preparing it, and answer as if it ran.
     */
    public function exec(string $statement): int|false
    {
        $this->received[] = $statement;

        return 0;
    }

    /**
     * Record a statement, and prepare it on SQLite unless it is a server's SET.
     *
     * @param  array<int, mixed>  $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->received[] = $query;

        return parent::prepare(preg_match('/^\s*set\b/i', $query) === 1 ? 'select 1' : $query, $options);
    }
}
