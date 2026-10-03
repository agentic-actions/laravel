<script setup lang="ts">
/**
 * Window: a simplified app or browser frame for a UI mock, with three grey
 * dots, a title, and an optional URL bar.
 *
 * Use it to show what a person sees on a page that uses the package: a chat
 * panel, a confirmation card, a consent screen, a table. Draw the inside with
 * Bubble, ListRow, MockButton, Skeleton, Pill and plain text, in greys with an
 * accent only where it carries meaning. Its text is real text, read in order.
 *
 * Props: title (in the bar, and the frame's accessible name), url (a URL bar
 * in the monospace, for a browser), width (a max width such as "360px";
 * default the container's), flush (no padding inside, for a table).
 *
 *   <Window url="example.com/teams/design" title="Posts" width="420px">
 *   <ListRow label="Spring meetup" status="draft" />
 *   </Window>
 */
withDefaults(defineProps<{ title?: string; url?: string; width?: string; flush?: boolean }>(), { flush: false })
</script>

<template>
  <div class="aa-window" role="group" :aria-label="title ?? url ?? 'App window'" :style="width ? { maxWidth: width } : undefined">
    <div class="aa-window__bar">
      <span class="aa-window__dots" aria-hidden="true"><i /><i /><i /></span>
      <span v-if="url" class="aa-window__url">{{ url }}</span>
      <span v-else-if="title" class="aa-window__title">{{ title }}</span>
    </div>
    <div class="aa-window__body" :class="{ 'is-flush': flush }">
      <slot />
    </div>
  </div>
</template>

<style>
.aa-window {
  width: 100%;
  min-width: 0;
  overflow: hidden;
  border: 1px solid var(--aa-line-strong);
  border-radius: var(--aa-radius-lg);
  background: var(--aa-surface);
  box-shadow: var(--aa-shadow);
  color: var(--aa-ink);
  font-size: var(--aa-fs-sm);
  line-height: 1.5;
  text-align: left;
}

.aa-window__bar {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 38px;
  padding: 0 12px;
  border-bottom: 1px solid var(--aa-line);
}

.aa-window__dots {
  display: inline-flex;
  flex: none;
  gap: 5px;
}

.aa-window__dots i {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--aa-line-strong);
}

.aa-window__url,
.aa-window__title {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.aa-window__url {
  flex: 1;
  padding: 2px 10px;
  border: 1px solid var(--aa-line);
  border-radius: var(--aa-radius-sm);
  background: var(--aa-ground);
  color: var(--aa-ink-2);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
}

.aa-window__title {
  color: var(--aa-ink-2);
  font-size: var(--aa-fs-sm);
  font-weight: 500;
}

.aa-window__body {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px;
}

.aa-window__body.is-flush {
  padding: 0;
  gap: 0;
}

.vp-doc .aa-window p {
  margin: 0;
}
</style>
