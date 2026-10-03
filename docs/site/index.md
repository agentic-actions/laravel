---
layout: home
title: Agentic Actions for Laravel
titleTemplate: false
markdownStyles: false
---

<HomeFrame>

<HomeHero />

<HomeSection id="every-caller" eyebrow="01 · One action" title="One action, every caller" lede="Mark the class #[Expose] for the route, agents and MCP; Artisan and the queue reach it either way." more="One action, every caller" more-link="/concepts/how-it-works">

<Hub label="The callers of CreatePost" :callers="[
  { label: 'Web form and JSON', detail: '/actions/create-post', icon: 'form' },
  { label: 'Artisan', detail: 'actions:run create-post', icon: 'terminal' },
  { label: 'The queue', detail: 'CreatePost::dispatch()', icon: 'queue' },
  { label: 'TypeScript', detail: 'createPost()', icon: 'ts' },
  { label: 'A laravel/ai agent', detail: 'a tool in its toolset', icon: 'agent', tone: 'agent' },
  { label: 'MCP clients', detail: 'the create-post tool', icon: 'mcp', tone: 'mcp' },
]">
<CodeCard file="app/Actions/CreatePost.php">

```php
#[Expose]
final class CreatePost extends Action
{
    protected ?Effect $effect = Effect::Write;

    public function schema(JsonSchema $schema): array { … }
    public function authorize(ActionContext $context): bool { … }
    public function handle(ActionContext $context, ValidatedInput $input) { … }
}
```

</CodeCard>
</Hub>

</HomeSection>

<HomeSection id="pipeline" eyebrow="02 · The pipeline" title="Every call takes the same steps" lede="Whoever calls, the same checks run. A step before authorize() that says no answers “not found”, so a caller cannot tell a hidden action from a missing one." more="The pipeline" more-link="/concepts/pipeline">

<Flow compact label="The pipeline">
<Step title="exposure" note="#[Expose] lets the caller in" />
<Step title="token abilities" note="actions:write for a Write" />
<Step title="tenant membership" tone="tenant" note="the person is in the team" branch="not a member: 404" />
<Step title="authorize()" code note="before the input, or after validation" />
<Step title="validation" note="schema() as rules" />
<Step title="handle()" code tone="pass" note="does the work" />
<Step title="output allowlist" note="only declared keys leave" />
</Flow>

</HomeSection>

<HomeSection id="features" eyebrow="03 · Around the pipeline" title="Built for a person working with an agent" flush>

<HomeFeatures>
<HomeFeature title="The copilot" text="An agent works on the page. Each action call shows as a live row, and the page reloads what the write touched." link="/concepts/agents">
<MockCopilot />
</HomeFeature>
<HomeFeature title="Confirmations" text="A Destructive or External call waits until the person confirms it, on a card the server builds." link="/concepts/confirmations">
<MockConfirm />
</HomeFeature>
<HomeFeature title="Tenants" text="Every tenant-scoped call runs inside one team. A stranger gets the same 404 as a team that does not exist." link="/concepts/tenants">
<MockTenants />
</HomeFeature>
<HomeFeature title="MCP and OAuth" text="Clients connect with a person's token or sign in with OAuth, and reach Read and Write actions only." link="/concepts/mcp">
<MockConsent />
</HomeFeature>
<HomeFeature title="Tables and charts" text="A Read action's rows reach the person as a table. The numbers come from your query; the model reads a short copy." link="/concepts/tables">
<MockTable />
</HomeFeature>
<HomeFeature title="Testing" text="Fake every action, assert what ran, and pin what each toolset holds, so a widened toolset fails a test." link="/testing">
<MockTests />
</HomeFeature>
</HomeFeatures>

</HomeSection>

<HomeCta />

</HomeFrame>
