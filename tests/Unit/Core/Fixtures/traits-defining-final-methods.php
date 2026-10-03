<?php

/*
 * Another package's traits, each defining one method that Action declares final, as AsAction defines all three and
 * AsJob defines dispatch(). Each signature is compatible with Action's, so a class using one fails to load only
 * because the method is final. FinalMethodsTest mixes each into an action in a separate process; nothing else loads it.
 */

namespace Tests\Unit\Core\Fixtures;

use Illuminate\Foundation\Bus\PendingDispatch;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

trait DefinesRun
{
    /**
     * Another package's run().
     *
     * @param  array<string, mixed>  $input
     */
    public static function run(array $input, mixed $context): mixed
    {
        return null;
    }
}

trait DefinesInvoke
{
    /**
     * Another package's __invoke().
     */
    public function __invoke(mixed ...$arguments): Response
    {
        return new Response;
    }
}

trait DefinesDispatch
{
    /**
     * Another package's dispatch().
     *
     * @param  array<string, mixed>  $input
     */
    public static function dispatch(array $input, mixed $context): PendingDispatch
    {
        return new PendingDispatch(new stdClass);
    }
}
