<script setup lang="ts">
/**
 * ListRow: one row of a list in a UI mock, with a leading icon or tone dot, a
 * label, an optional detail line, and a status pill at the end.
 *
 * Use it for the copilot's live tool rows ("Saving…", "Saved"), team members,
 * connected apps, test results.
 *
 * Props: label, detail (a second, muted line), tone (colours the dot or icon
 * and the pill), icon (an Icon name instead of the dot), status (the pill's
 * text), mono (the label in the monospace), boxed (a bordered row, as the
 * copilot draws its tool rows).
 *
 *   <ListRow boxed icon="check" tone="pass" label="Saved" status="done" />
 */
import Icon from './Icon.vue'
import Pill from './Pill.vue'
import { toneClass, type Tone } from './tones'

withDefaults(
  defineProps<{ label: string; detail?: string; tone?: Tone; icon?: string; status?: string; mono?: boolean; boxed?: boolean }>(),
  { tone: 'neutral', mono: false, boxed: false }
)
</script>

<template>
  <div class="aa-row" :class="[toneClass(tone), { 'is-boxed': boxed }]">
    <Icon v-if="icon" :name="icon" :size="16" class="aa-row__icon" />
    <span v-else class="aa-row__dot" aria-hidden="true" />
    <span class="aa-row__text">
      <span class="aa-row__label" :class="{ 'is-mono': mono }">{{ label }}</span>
      <span v-if="detail" class="aa-row__detail">{{ detail }}</span>
    </span>
    <Pill v-if="status" :tone="tone">{{ status }}</Pill>
  </div>
</template>

<style>
.aa-row {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
  padding: 4px 0;
}

.aa-row.is-boxed {
  padding: 7px 10px;
  border: 1px solid var(--aa-line);
  border-radius: var(--aa-radius-sm);
  background: var(--aa-surface);
}

.aa-row__icon {
  color: var(--tone);
}

.aa-row.aa-tone-neutral .aa-row__icon {
  color: var(--aa-muted);
}

.aa-row__dot {
  flex: none;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--tone);
}

.aa-row.aa-tone-neutral .aa-row__dot {
  background: var(--aa-line-strong);
}

.aa-row__text {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.aa-row__label {
  overflow: hidden;
  color: var(--aa-ink);
  font-size: var(--aa-fs-sm);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.aa-row__label.is-mono {
  overflow-wrap: anywhere;
  white-space: normal;
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
}

.aa-row__detail {
  color: var(--aa-muted);
  font-size: var(--aa-fs-xs);
}
</style>
