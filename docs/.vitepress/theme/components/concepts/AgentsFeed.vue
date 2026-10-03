<script setup lang="ts">
/**
 * AgentsFeed: the writes that have no row on the open page, each recording the
 * keys it touched in the change feed, which the page polls and reloads like a
 * done row (docs/copilot.md#writes-made-elsewhere, docs/concepts.md#the-change-feed).
 *
 *   <Figure caption="…"><AgentsFeed /></Figure>
 */
const callers = [
  { label: 'An MCP client', detail: 'a write over MCP', icon: 'mcp', tone: 'mcp' as const },
  { label: 'A queued job', detail: 'a queued run', icon: 'queue' },
  { label: 'Another member', detail: 'the same tenant', icon: 'team', tone: 'tenant' as const },
  { label: 'Another tab', detail: 'the same person', icon: 'globe' },
]
</script>

<template>
  <Hub label="Writes made elsewhere" :callers="callers">
    <Card eyebrow="change feed" title="POST …/actions/_changes" mono>
      <ul class="aa-agents-feed">
        <li>Keys only, never ids or values</li>
        <li>Polled every 15 s while the page is visible</li>
        <li>Reloaded like a done row, held while an editor is dirty</li>
      </ul>
    </Card>
  </Hub>
</template>

<style>
.vp-doc ul.aa-agents-feed {
  margin: 8px 0 0;
  padding: 0;
  list-style: none;
  color: var(--aa-ink-2);
  font-size: var(--aa-fs-sm);
}

.vp-doc ul.aa-agents-feed li {
  position: relative;
  margin: 0;
  padding-left: 14px;
}

.vp-doc ul.aa-agents-feed li + li {
  margin-top: 4px;
}

.vp-doc ul.aa-agents-feed li::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0.62em;
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: var(--aa-line-strong);
}
</style>
