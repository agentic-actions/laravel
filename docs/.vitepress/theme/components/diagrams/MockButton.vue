<script setup lang="ts">
/**
 * MockButton: something drawn like a button in a UI mock. It is not a button
 * (nothing happens on a click and it takes no focus), so a reader never tabs
 * into a picture.
 *
 * Use it for Confirm and Decline on a confirmation card, Allow and Deny on the
 * consent screen, a send button.
 *
 * Props: primary (filled in the ink), tone (tints a secondary button's border).
 *
 *   <MockButton>Decline</MockButton> <MockButton primary>Confirm</MockButton>
 */
import { toneClass, type Tone } from './tones'

withDefaults(defineProps<{ primary?: boolean; tone?: Tone }>(), { primary: false, tone: 'neutral' })
</script>

<template>
  <span class="aa-mockbutton" :class="[toneClass(tone), { 'is-primary': primary }]"><slot /></span>
</template>

<style>
.aa-mockbutton {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 28px;
  padding: 0 12px;
  border: 1px solid var(--aa-line-strong);
  border-radius: var(--aa-radius-sm);
  background: var(--aa-surface);
  color: var(--aa-ink);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  letter-spacing: 0.02em;
  white-space: nowrap;
}

.aa-mockbutton:not(.aa-tone-neutral) {
  border-color: var(--tone-line);
  color: var(--tone);
}

.aa-mockbutton.is-primary {
  border-color: var(--aa-ink);
  background: var(--aa-ink);
  color: var(--aa-surface);
}
</style>
