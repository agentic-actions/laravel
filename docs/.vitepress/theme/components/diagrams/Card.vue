<script setup lang="ts">
/**
 * Card: a white (or near-black) box with an optional eyebrow, a title and a
 * status pill, for one thing in a diagram: a class, a request, a table row
 * the reader should notice.
 *
 * Use it inside Columns, a Hub's centre or a Figure. A tone tints its border
 * and puts a dot before the title; leave it neutral unless the card stands for
 * an accent's meaning.
 *
 * Props: title, eyebrow (a monospace label above the title), tone, mono (the
 * title in the monospace, for a class or a route), dashed (a dashed border,
 * for something that does not exist yet or never runs), status and statusTone
 * (a Pill beside the title).
 *
 *   <Card eyebrow="action" title="DeletePost" mono tone="wait" status="waits for you" status-tone="wait">
 *   Runs only after the person confirms.
 *   </Card>
 */
import Pill from './Pill.vue'
import { toneClass, type Tone } from './tones'

withDefaults(
  defineProps<{ title?: string; eyebrow?: string; tone?: Tone; mono?: boolean; dashed?: boolean; status?: string; statusTone?: Tone }>(),
  { tone: 'neutral', mono: false, dashed: false }
)
</script>

<template>
  <div class="aa-card" :class="[toneClass(tone), { 'is-dashed': dashed }]">
    <div v-if="eyebrow" class="aa-card__eyebrow">{{ eyebrow }}</div>
    <div v-if="title || status" class="aa-card__head">
      <span v-if="title" class="aa-card__title" :class="{ 'is-mono': mono }">
        <span v-if="tone !== 'neutral'" class="aa-card__dot" aria-hidden="true" />{{ title }}
      </span>
      <Pill v-if="status" :tone="statusTone ?? tone">{{ status }}</Pill>
    </div>
    <div v-if="$slots.default" class="aa-card__body"><slot /></div>
  </div>
</template>

<style>
.aa-card {
  min-width: 0;
  padding: 14px 16px;
  border: 1px solid var(--aa-line-strong);
  border-radius: var(--aa-radius);
  background: var(--aa-surface);
  box-shadow: var(--aa-shadow);
  color: var(--aa-ink);
  font-size: var(--aa-fs-sm);
  line-height: 1.5;
}

.aa-card:not(.aa-tone-neutral) {
  border-color: var(--tone-line);
}

.aa-card.is-dashed {
  border-style: dashed;
  box-shadow: none;
}

.aa-card__eyebrow {
  margin-bottom: 4px;
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  letter-spacing: var(--aa-tracking-label);
  text-transform: uppercase;
}

.aa-card__head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 6px 10px;
}

.aa-card__title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: var(--aa-fs-md);
  font-weight: 500;
}

.aa-card__title.is-mono {
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-sm);
}

.aa-card__dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--tone);
}

.aa-card__head + .aa-card__body {
  margin-top: 6px;
}

.aa-card__body {
  color: var(--aa-ink-2);
}

.vp-doc .aa-card p {
  margin: 0;
}

.vp-doc .aa-card p + p {
  margin-top: 6px;
}
</style>
