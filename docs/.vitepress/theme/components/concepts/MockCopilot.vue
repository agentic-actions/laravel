<script setup lang="ts">
/**
 * MockCopilot: the copilot panel mid-turn. The person asks, a Read row is
 * done, a Write row is still running, and the agent answers.
 *
 * The row labels are the package's defaults for a Read and a Write
 * (lang/en/activity.php) and the pills its statuses (docs/copilot.md#statuses).
 *
 *   <Figure caption="Each tool call is a row." center><MockCopilot /></Figure>
 */
</script>

<template>
  <Window title="Copilot" width="420px" class="aa-mock-copilot">
    <Bubble>Draft a post about the spring meetup.</Bubble>
    <ListRow boxed icon="check" tone="pass" label="Looked it up" status="done" />
    <ListRow boxed icon="agent" tone="agent" label="Saving…" status="running" class="is-running" />
    <Bubble from="agent">Saving it as a draft for you.</Bubble>
  </Window>
</template>

<style>
.aa-mock-copilot .is-running .aa-pill__dot {
  animation: aa-pulse 1.6s ease-in-out infinite;
}

@keyframes aa-pulse {
  50% {
    opacity: 0.25;
  }
}
</style>
