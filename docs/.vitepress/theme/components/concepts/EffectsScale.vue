<script setup lang="ts">
/**
 * EffectsScale: the four effects, left to right from "changes nothing" to
 * "reaches outside", each with the token ability a call needs, under two
 * dimension lines: Read and Write a model may call, Destructive and External
 * wait for the person on every agent call and never reach MCP.
 *
 * Wording from src/Effect.php, docs/concepts.md#effects and the abilities in
 * docs/security.md. Below 640px the two groups stack, below 420px every card.
 */
import Icon from '../diagrams/Icon.vue'
import Pill from '../diagrams/Pill.vue'
import type { Tone } from '../diagrams/tones'

interface Effect {
  name: string
  meaning: string
  ability: string
}

const groups: { tone: Tone; label: string; effects: Effect[] }[] = [
  {
    tone: 'pass',
    label: 'a model may call it',
    effects: [
      { name: 'Read', meaning: 'changes nothing', ability: 'actions:read' },
      { name: 'Write', meaning: "changes the actor's own data, which the actor could enter again", ability: 'actions:write' },
    ],
  },
  {
    tone: 'wait',
    label: 'each agent call waits for the person',
    effects: [
      { name: 'Destructive', meaning: 'removes something other people rely on, or the actor cannot recreate', ability: 'actions:destructive' },
      { name: 'External', meaning: "reaches people or systems outside the actor's own data: an email, a payment, a webhook", ability: 'actions:external' },
    ],
  },
]
</script>

<template>
  <div class="aa-fx-scale-c">
    <div class="aa-fx-scale">
      <div v-for="group in groups" :key="group.label" class="aa-fx-scale__group">
        <div class="aa-fx-scale__dim" :class="`aa-tone-${group.tone}`">
          <span class="aa-fx-scale__line" aria-hidden="true" />
          <Pill :tone="group.tone" solid>{{ group.label }}</Pill>
          <span class="aa-fx-scale__line" aria-hidden="true" />
        </div>
        <ul class="aa-fx-scale__cards">
          <li v-for="effect in group.effects" :key="effect.name" class="aa-fx-scale__card">
            <span class="aa-fx-scale__eyebrow">Effect::{{ effect.name }}</span>
            <span class="aa-fx-scale__name">{{ effect.name }}</span>
            <span class="aa-fx-scale__meaning">{{ effect.meaning }}</span>
            <span class="aa-fx-scale__ability">
              <Icon name="key" :size="14" />
              <span class="aa-fx-sr">token ability:</span>
              <code>{{ effect.ability }}</code>
            </span>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<style>
.aa-fx-scale-c {
  container-type: inline-size;
}

.aa-fx-scale {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;
}

.aa-fx-scale__group {
  display: flex;
  flex-direction: column;
  gap: 14px;
  min-width: 0;
}

.aa-fx-scale__dim {
  display: flex;
  align-items: center;
  gap: 8px;
}

.aa-fx-scale__line {
  position: relative;
  flex: 1 1 0;
  min-width: 12px;
  height: 12px;
}

.aa-fx-scale__line::before {
  content: '';
  position: absolute;
  top: 50%;
  right: 0;
  left: 0;
  border-top: 1px solid var(--tone-line);
}

.aa-fx-scale__line:first-child {
  border-left: 1px solid var(--tone-line);
}

.aa-fx-scale__line:last-child {
  border-right: 1px solid var(--tone-line);
}

.aa-fx-scale__cards,
.vp-doc .aa-fx-scale__cards {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  flex: 1;
  margin: 0;
  padding: 0;
  list-style: none;
}

.vp-doc .aa-fx-scale__cards li + li {
  margin-top: 0;
}

.aa-fx-scale__card {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  padding: 14px 14px 12px;
  border: 1px solid var(--aa-line-strong);
  border-radius: var(--aa-radius);
  background: var(--aa-surface);
  box-shadow: var(--aa-shadow);
}

.aa-fx-scale__eyebrow {
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
}

.aa-fx-scale__name {
  color: var(--aa-ink);
  font-size: var(--aa-fs-lg);
  font-weight: 500;
  line-height: 1.3;
}

.aa-fx-scale__meaning {
  flex: 1;
  color: var(--aa-ink-2);
  font-size: var(--aa-fs-sm);
  line-height: 1.45;
}

.aa-fx-scale__ability {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 8px;
  padding-top: 10px;
  border-top: 1px dashed var(--aa-line);
  color: var(--aa-muted);
}

.aa-fx-scale__ability code,
.vp-doc .aa-fx-scale__ability code {
  padding: 0;
  background: none;
  color: var(--aa-ink-2);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
}

.aa-fx-sr {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

@container (max-width: 640px) {
  .aa-fx-scale {
    grid-template-columns: minmax(0, 1fr);
    gap: 24px;
  }
}

@container (max-width: 420px) {
  .aa-fx-scale__cards,
  .vp-doc .aa-fx-scale__cards {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
