<script setup lang="ts">
/**
 * McpCallFlow: the clients that hold a person's token, the package's MCP
 * server, and what each request passes on its way to an action, in order
 * (docs/mcp.md#tokens-and-abilities, #throttle, docs/concepts.md#the-pipeline).
 *
 * The kit's Hub puts the clients around a centre; the centre here is a framed
 * vertical Flow. The steps are not numbered: the pipeline page owns the
 * pipeline's numbers.
 */
const steps = [
  { title: 'auth:sanctum', code: true, note: 'reads the bearer token', branch: 'no valid token: 401' },
  { title: 'throttle', note: '60 a minute, per person', branch: 'past it: 429' },
  { title: 'the MCP door', note: 'Read or Write, and #[Expose] allows MCP', branch: 'never listed' },
  { title: 'token abilities', note: 'named by the token; * never counts', branch: 'not named: not listed' },
  { title: 'the rest of the pipeline', note: 'membership, authorize(), validation, handle()', tone: 'pass' as const },
]

const clients = [
  { label: 'Claude Code', detail: 'claude mcp add', icon: 'terminal', tone: 'mcp' as const },
  { label: 'Cursor', detail: '.cursor/mcp.json', icon: 'code', tone: 'mcp' as const },
  { label: 'Claude Desktop', detail: 'through mcp-remote', icon: 'chat', tone: 'mcp' as const },
]
</script>

<template>
  <Hub :callers="clients" :split="3" label="MCP clients, each with a person's bearer token">
    <div class="aa-mcp-server">
      <div class="aa-mcp-server__head">
        <span class="aa-mcp-server__eyebrow">the package's MCP server</span>
        <span class="aa-mcp-server__routes">
          <Pill tone="mcp" :dot="false">POST mcp/actions</Pill>
          <Pill tone="tenant" :dot="false">POST mcp/t/{tenant}</Pill>
        </span>
      </div>
      <Flow vertical label="What each request passes, in order">
        <Step v-for="step in steps" :key="step.title" :title="step.title" :code="step.code" :note="step.note" :tone="step.tone" :branch="step.branch" />
      </Flow>
    </div>
  </Hub>
</template>

<style>
.aa-mcp-server {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 16px;
  border: 1px dashed var(--aa-mcp-line);
  border-radius: var(--aa-radius-lg);
  background: var(--aa-ground);
}

.aa-mcp-server__head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.aa-mcp-server__eyebrow {
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  letter-spacing: var(--aa-tracking-label);
  text-transform: uppercase;
}

.aa-mcp-server__routes {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

@container (max-width: 480px) {
  .aa-mcp-server {
    padding: 12px;
  }
}
</style>
