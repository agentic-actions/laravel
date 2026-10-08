<?php

namespace Tests\Unit\PHPStan;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * With the package's extension.neon, PHPStan reads an action's run() as what its handle() returns: the value run()
 * hands back unchanged. data/run.php holds the calls and the type each must have.
 */
final class RunReturnTypeTest extends TypeInferenceTestCase
{
    /**
     * @return iterable<mixed>
     */
    public static function asserts(): iterable
    {
        yield from self::gatherAssertTypes(__DIR__.'/data/run.php');
    }

    #[DataProvider('asserts')]
    public function test_run_has_the_type_handle_returns(string $assertType, string $file, mixed ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [dirname(__DIR__, 3).'/extension.neon'];
    }
}
