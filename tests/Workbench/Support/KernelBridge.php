<?php

namespace Tests\Workbench\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * Lets laravel/mcp's own Client talk to the workbench with no network: the client sends through Laravel's Http facade,
 * so a fake replays each of its requests through the application's HTTP kernel and hands back the kernel's answer.
 */
final class KernelBridge
{
    /**
     * Replay every outgoing request through the test's kernel. The guards are forgotten before each request, so a
     * second token in one test is really read.
     */
    public static function install(TestCase $test): void
    {
        Http::fake(function (Request $request) use ($test) {
            app('auth')->forgetGuards();

            $server = ['CONTENT_TYPE' => 'application/json'];

            foreach ($request->headers() as $name => $values) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = implode(', ', $values);
            }

            $response = $test->call($request->method(), (string) parse_url($request->url(), PHP_URL_PATH), server: $server, content: $request->body());

            return Http::response($response->getContent(), $response->getStatusCode(), $response->headers->all());
        });
    }
}
