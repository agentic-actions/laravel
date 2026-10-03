<script setup lang="ts">
/**
 * AgentsCopilot: a page with the copilot panel beside it, mid-turn. The done
 * Write row carries its touches, and the page has just reloaded that prop;
 * under the window, the statuses a row ends in and the one thing that holds a
 * reload.
 *
 * Wording: "Draft saved" is the workbench CreatePost's activityLabel(),
 * "Looking it up…" the package's running label for a Read
 * (lang/en/activity.php), the statuses those of docs/copilot.md#statuses, and
 * the reload useActionSync()'s router.reload({ only: touches })
 * (docs/copilot.md#the-page-follows-the-writes). Composed here because the kit
 * has no Window split into a page and a side panel.
 *
 *   <Figure caption="…"><AgentsCopilot /></Figure>
 */
</script>

<template>
  <div class="aa-agents-copilot">
    <Window url="example.com/posts" flush>
      <div class="aa-agents-copilot__split">
        <div class="aa-agents-copilot__page" role="group" aria-label="The page">
          <div class="aa-agents-copilot__head">
            <span class="aa-agents-copilot__title">Posts</span>
            <Pill tone="pass" solid>reloaded: posts</Pill>
          </div>
          <ListRow boxed tone="pass" label="Spring meetup" status="draft" class="is-new" />
          <ListRow label="Release notes" status="published" />
          <ListRow label="Hello, world" status="published" />
          <Skeleton :lines="2" />
        </div>
        <div class="aa-agents-copilot__panel" role="group" aria-label="The copilot panel">
          <span class="aa-agents-copilot__eyebrow">Copilot</span>
          <Bubble>Draft a post about the spring meetup.</Bubble>
          <ListRow boxed icon="check" tone="pass" label="Draft saved" detail="touches: posts" status="done" />
          <ListRow boxed icon="agent" tone="agent" label="Looking it up…" status="running" class="is-running" />
        </div>
      </div>
    </Window>

    <div class="aa-agents-copilot__legend">
      <div class="aa-agents-copilot__legend-row">
        <span class="aa-agents-copilot__legend-label">A row ends as</span>
        <ul>
          <li><Pill tone="pass">done</Pill></li>
          <li><Pill tone="refuse">refused</Pill></li>
          <li><Pill tone="refuse">failed</Pill></li>
          <li><Pill>ended</Pill></li>
          <li><Pill>declined</Pill></li>
        </ul>
      </div>
      <div class="aa-agents-copilot__legend-row">
        <span class="aa-agents-copilot__legend-label">A reload</span>
        <Pill tone="wait">waits while an editor is dirty</Pill>
      </div>
    </div>
  </div>
</template>

<style>
.aa-agents-copilot {
  container-type: inline-size;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.aa-agents-copilot__split {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
}

.aa-agents-copilot__page,
.aa-agents-copilot__panel {
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-width: 0;
  padding: 16px;
}

.aa-agents-copilot__panel {
  border-left: 1px solid var(--aa-line);
  background: var(--aa-ground);
}

.aa-agents-copilot__head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  margin-bottom: 2px;
}

.aa-agents-copilot__title {
  font-size: var(--aa-fs-md);
  font-weight: 500;
}

.aa-agents-copilot__eyebrow,
.aa-agents-copilot__legend-label {
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  letter-spacing: var(--aa-tracking-label);
  text-transform: uppercase;
}

.aa-agents-copilot .aa-row.is-new {
  border-color: var(--aa-pass-line);
  background: var(--aa-pass-fill);
}

.aa-agents-copilot .aa-row.is-running .aa-pill__dot {
  animation: aa-pulse 1.6s ease-in-out infinite;
}

.aa-agents-copilot__legend {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}

.aa-agents-copilot__legend-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 8px 12px;
}

.vp-doc .aa-agents-copilot__legend ul {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.vp-doc .aa-agents-copilot__legend li + li {
  margin-top: 0;
}

@keyframes aa-pulse {
  50% {
    opacity: 0.25;
  }
}

@container (max-width: 520px) {
  .aa-agents-copilot__split {
    grid-template-columns: minmax(0, 1fr);
  }

  .aa-agents-copilot__panel {
    border-top: 1px solid var(--aa-line);
    border-left: 0;
  }
}
</style>
