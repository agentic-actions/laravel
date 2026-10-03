<?php

namespace Tests\Feature\Security;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\ActionsManager;
use AgenticActions\Effect;
use AgenticActions\Exceptions\ReadActionWrote;
use AgenticActions\Outcome;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Security\WritableTables;
use Closure;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Workbench\App\Models\User;

/*
 * A Read cannot write through Laravel's database connection: statements a hostile or careless Read might send, run
 * end to end through the pipeline on a real connection.
 */

beforeEach(function () {
    GuardProbe::$statements = null;

    $this->context = ActionContext::http(User::factory()->create());
});

/**
 * Run GuardProbe with the given statements and return the outcome.
 */
function probeRead(Closure $statements): Outcome
{
    GuardProbe::$statements = $statements;

    return app(ActionsManager::class)->attempt(GuardProbe::class, [], test()->context);
}

it('refuses INTO hidden after a number literal before the statement reaches the database', function () {
    Exceptions::fake();

    $outcome = probeRead(fn () => DB::select("select 1.5into outfile '/tmp/agentic-actions-probe'"));

    expect($outcome->kind())->toBe(OutcomeKind::Failed)
        ->and($outcome->exception())->toBeInstanceOf(ReadActionWrote::class);
});

it('keeps the cache table of another connection read-only on the default connection', function () {
    config([
        'database.connections.cache_db' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'cache.stores.database.connection' => 'cache_db',
        'cache.stores.database.lock_connection' => 'cache_db',
    ]);

    Schema::connection('cache_db')->create('cache', function ($table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });

    Exceptions::fake();

    $default = probeRead(fn () => DB::table('cache')->insert(['key' => 'k', 'value' => 'v', 'expiration' => 0]));
    $own = probeRead(fn () => DB::connection('cache_db')->table('cache')->insert(['key' => 'k', 'value' => 'v', 'expiration' => 0]));
    $store = probeRead(fn () => Cache::store('database')->put('probe', 'cached', 60));

    expect($default->kind())->toBe(OutcomeKind::Failed)
        ->and($default->exception())->toBeInstanceOf(ReadActionWrote::class)
        ->and(DB::table('cache')->count())->toBe(0)
        ->and($own->ok())->toBeTrue()
        ->and($store->ok())->toBeTrue()
        ->and(DB::connection('cache_db')->table('cache')->count())->toBe(2);
});

it('keeps the sessions table read-only while sessions are not database-backed', function () {
    config(['session.driver' => 'file']);

    Exceptions::fake();

    $outcome = probeRead(fn () => DB::table('sessions')->insert(['id' => 'forged', 'payload' => 'x', 'last_activity' => 0]));

    expect($outcome->exception())->toBeInstanceOf(ReadActionWrote::class)
        ->and(DB::table('sessions')->count())->toBe(0);
});

it('refuses another schema\'s table that shares a writable table\'s name', function () {
    DB::statement("attach database ':memory:' as other");
    DB::statement('create table other.cache (key text, value text, expiration integer)');

    Exceptions::fake();

    $outcome = probeRead(fn () => DB::insert("insert into other.cache (key, value, expiration) values ('k', 'v', 0)"));

    expect($outcome->exception())->toBeInstanceOf(ReadActionWrote::class)
        ->and(DB::table('other.cache')->count())->toBe(0);
});

it('writes a schema-qualified table\'s prefix on its last segment, as the grammar does', function () {
    config(['agentic-actions.reads.writable_tables' => ['public.audit']]);

    $connection = new SQLiteConnection(fn () => throw new LogicException('Never connects.'), ':memory:', 'app_', ['name' => config('database.default')]);

    expect((new WritableTables)->for($connection))->toContain('public.app_audit')
        ->and($connection->getQueryGrammar()->wrapTable('public.audit'))->toBe('"public"."app_audit"');
});

/**
 * A Read that runs whatever statements the test gives it.
 */
final class GuardProbe extends Action
{
    /**
     * The statements handle() runs.
     */
    public static ?Closure $statements = null;

    protected ?Effect $effect = Effect::Read;

    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    public function handle(ActionContext $context): mixed
    {
        (self::$statements ?? fn () => null)();

        return null;
    }
}
