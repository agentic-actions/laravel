<script setup lang="ts">
/**
 * EffectsChips: labelled rows of small chips that sit in the text, not in a
 * figure: a group label on the left (above it when narrow) and its chips, each
 * a monospace name, a muted detail and an icon. A toned chip takes its
 * accent's tint, so only the agent and MCP chips carry colour.
 *
 * Used on the Effects and surfaces page for the six surfaces (src/Surface.php)
 * and the five doors (docs/concepts.md#doors). Each group is a list named by
 * its label, so a screen reader reads the label, then the chips in order.
 *
 * Props: label (the accessible name of the whole strip), groups, a list of
 * { label, chips } where a chip is { name, detail?, icon, tone? }.
 *
 *   <EffectsChips label="Surfaces" :groups="[
 *     { label: 'model-driven', chips: [{ name: 'Agent', detail: 'a laravel/ai tool', icon: 'agent', tone: 'agent' }] },
 *   ]" />
 */
import { useId } from 'vue'
import Icon from '../diagrams/Icon.vue'
import type { Tone } from '../diagrams/tones'

interface Chip {
  name: string
  detail?: string
  icon: string
  tone?: Tone
}

defineProps<{ label?: string; groups: { label: string; chips: Chip[] }[] }>()

const id = useId()
</script>

<template>
  <div class="aa-fx-chips" role="group" :aria-label="label">
    <div v-for="(group, g) in groups" :key="group.label" class="aa-fx-chips__group">
      <span :id="`${id}-${g}`" class="aa-fx-chips__label">{{ group.label }}</span>
      <ul class="aa-fx-chips__list" :aria-labelledby="`${id}-${g}`">
        <li v-for="chip in group.chips" :key="chip.name" class="aa-fx-chips__chip" :class="`aa-tone-${chip.tone ?? 'neutral'}`">
          <Icon :name="chip.icon" :size="16" />
          <span class="aa-fx-chips__name">{{ chip.name }}</span>
          <span v-if="chip.detail" class="aa-fx-chips__detail">{{ chip.detail }}</span>
        </li>
      </ul>
    </div>
  </div>
</template>

<style>
.aa-fx-chips {
  margin: 20px 0 24px;
  container-type: inline-size;
}

.aa-fx-chips__group {
  display: grid;
  grid-template-columns: 128px minmax(0, 1fr);
  align-items: center;
  gap: 8px 16px;
  padding: 12px 0;
}

.aa-fx-chips__group + .aa-fx-chips__group {
  border-top: 1px dashed var(--aa-line-strong);
}

.aa-fx-chips__label {
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  letter-spacing: var(--aa-tracking-label);
  line-height: 1.4;
  text-transform: uppercase;
}

.aa-fx-chips__list,
.vp-doc .aa-fx-chips__list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.vp-doc .aa-fx-chips__list li + li {
  margin-top: 0;
}

.aa-fx-chips__chip {
  position: relative;
  display: inline-flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  padding: 7px 12px 7px 34px;
  border: 1px solid var(--aa-line-strong);
  border-radius: var(--aa-radius);
  background: var(--aa-surface);
  line-height: 1.3;
}

.aa-fx-chips__chip:not(.aa-tone-neutral) {
  border-color: var(--tone-line);
  background: var(--tone-fill);
}

.aa-fx-chips__chip .aa-icon {
  position: absolute;
  top: 8px;
  left: 11px;
  color: var(--aa-muted);
}

.aa-fx-chips__chip:not(.aa-tone-neutral) .aa-icon {
  color: var(--tone);
}

.aa-fx-chips__name {
  color: var(--aa-ink);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-sm);
}

.aa-fx-chips__detail {
  color: var(--aa-muted);
  font-size: var(--aa-fs-xs);
  overflow-wrap: anywhere;
}

.aa-fx-chips__chip:not(.aa-tone-neutral) .aa-fx-chips__detail {
  color: var(--aa-ink-2);
}

@container (max-width: 560px) {
  .aa-fx-chips__group {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
