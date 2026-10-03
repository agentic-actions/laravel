<?php

namespace Tests\Fixtures\Approvals;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Actions\Trace;

/**
 * A host's own tool that always asks for a person's approval, with a reason that must never reach the browser.
 */
final class HostConfirmedTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    /**
     * The tool's name.
     */
    public const NAME = 'host-confirmed';

    /**
     * The reason it asks with.
     */
    public const REASON = 'CANARY-REASON: the host tool asks first.';

    /**
     * The tool's name.
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * What the tool does.
     */
    public function description(): string
    {
        return 'Send a note on the host\'s own path.';
    }

    /**
     * A note.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'note' => $schema->string(),
        ];
    }

    /**
     * Record the call.
     */
    public function handle(Request $request): string
    {
        Trace::record('HostConfirmedTool::handle');

        return 'Sent.';
    }

    /**
     * Every call asks, with the canary reason.
     */
    protected function needsApproval(Request $request): Approval
    {
        return Approval::required(self::REASON);
    }
}
