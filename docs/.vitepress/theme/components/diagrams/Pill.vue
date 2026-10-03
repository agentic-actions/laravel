<script setup lang="ts">
/**
 * Pill: a short state in the monospace, such as "refused: 404", "waits for
 * you" or "actions:write", with a tone dot and a tinted border.
 *
 * Use it for a state or a label a reader should spot at a glance; a sentence
 * belongs in the text instead.
 *
 * Props: tone (default neutral), dot (default true), solid (a tinted fill
 * behind the text).
 *
 *   <Pill tone="refuse">refused: 404</Pill>
 */
import { toneClass, type Tone } from './tones'

withDefaults(defineProps<{ tone?: Tone; dot?: boolean; solid?: boolean }>(), { tone: 'neutral', dot: true, solid: false })
</script>

<template>
  <span class="aa-pill" :class="[toneClass(tone), { 'is-solid': solid }]">
    <span v-if="dot" class="aa-pill__dot" aria-hidden="true" />
    <span class="aa-pill__text"><slot /></span>
  </span>
</template>

<style>
.aa-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  padding: 2px 9px;
  border: 1px solid var(--tone-line);
  border-radius: 999px;
  background: var(--aa-surface);
  color: var(--tone);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  font-weight: 400;
  line-height: 18px;
}

.aa-pill.aa-tone-neutral {
  color: var(--aa-ink-2);
}

.aa-pill.is-solid {
  background: var(--tone-fill);
}

.aa-pill__dot {
  flex: none;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--tone);
}

.aa-pill__text {
  min-width: 0;
  overflow-wrap: anywhere;
}
</style>
