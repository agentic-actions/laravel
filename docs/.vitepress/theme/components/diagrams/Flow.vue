<script setup lang="ts">
/**
 * Flow: a sequence of Steps that runs left to right, with an arrow between
 * each, and stacks top to bottom when its container is narrower than 640px.
 * It is an ordered list, so a screen reader reads the steps in order.
 *
 * Use it for anything that happens in order: the pipeline, a confirmation's
 * round trip, the OAuth handshake. Children are Step components only.
 *
 * Props: label (the list's accessible name), vertical (always stack, for a
 * long flow in the text column), compact (smaller boxes, for a strip of six
 * or more steps), numbered (numbers each step).
 *
 *   <Flow label="A confirmation" numbered>
 *   <Step title="The agent calls DeletePost" tone="agent" />
 *   <Step title="The card waits" tone="wait" />
 *   <Step title="handle() runs" tone="pass" branch="declined: nothing runs" />
 *   </Flow>
 */
withDefaults(defineProps<{ label?: string; vertical?: boolean; compact?: boolean; numbered?: boolean }>(), {
  vertical: false,
  compact: false,
  numbered: false,
})
</script>

<template>
  <div class="aa-flow-c">
    <ol
      class="aa-flow"
      :class="{ 'is-vertical': vertical, 'is-compact': compact, 'is-numbered': numbered }"
      :aria-label="label"
    >
      <slot />
    </ol>
  </div>
</template>

<style>
.aa-flow-c {
  container-type: inline-size;
}

.aa-flow {
  --aa-flow-gap: 30px;
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: minmax(0, 1fr);
  grid-template-rows: auto auto;
  column-gap: var(--aa-flow-gap);
  margin: 0;
  padding: 0;
  list-style: none;
  counter-reset: aa-step;
}

.aa-flow.is-compact {
  --aa-flow-gap: 22px;
}

.vp-doc .aa-flow {
  margin: 0;
  padding: 0;
  list-style: none;
}

.vp-doc .aa-flow > li + li {
  margin-top: 0;
}

.aa-flow.is-vertical {
  grid-auto-flow: row;
  grid-auto-columns: auto;
  grid-template-rows: none;
  grid-template-columns: minmax(0, 1fr) auto;
  column-gap: 0;
  row-gap: var(--aa-flow-gap);
}

@container (max-width: 640px) {
  .aa-flow {
    grid-auto-flow: row;
    grid-auto-columns: auto;
    grid-template-rows: none;
    grid-template-columns: minmax(0, 1fr) auto;
    column-gap: 0;
    row-gap: var(--aa-flow-gap);
  }
}
</style>
