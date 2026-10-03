<script setup lang="ts">
/**
 * Bubble: one chat message in a UI mock: the person's on the right, the
 * agent's on the left with a small violet mark.
 *
 * Use it inside a Window to draw a copilot conversation, with ListRow for the
 * live tool rows between messages.
 *
 * Props: from ("person" or "agent", default person). The slot is the message.
 *
 *   <Bubble>Draft a post about the spring meetup.</Bubble>
 *   <Bubble from="agent">Saved as a draft.</Bubble>
 */
import Icon from './Icon.vue'

withDefaults(defineProps<{ from?: 'person' | 'agent' }>(), { from: 'person' })
</script>

<template>
  <div class="aa-bubble" :class="`is-${from}`">
    <span v-if="from === 'agent'" class="aa-bubble__mark aa-tone-agent"><Icon name="agent" :size="14" /></span>
    <span class="aa-visually-hidden">{{ from === 'agent' ? 'Agent:' : 'Person:' }}</span>
    <div class="aa-bubble__text"><slot /></div>
  </div>
</template>

<style>
.aa-bubble {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  max-width: 100%;
}

.aa-bubble.is-person {
  justify-content: flex-end;
}

.aa-bubble__text {
  max-width: 85%;
  padding: 7px 11px;
  border-radius: 12px;
  color: var(--aa-ink);
  font-size: var(--aa-fs-sm);
  line-height: 1.45;
}

.aa-bubble.is-person .aa-bubble__text {
  border-bottom-right-radius: 4px;
  background: var(--aa-sunken);
}

.aa-bubble.is-agent .aa-bubble__text {
  padding-left: 0;
  padding-right: 0;
}

.aa-bubble__mark {
  flex: none;
  display: grid;
  place-items: center;
  width: 22px;
  height: 22px;
  margin-top: 5px;
  border: 1px solid var(--tone-line);
  border-radius: 50%;
  background: var(--tone-fill);
  color: var(--tone);
}

.aa-visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

.vp-doc .aa-bubble p {
  margin: 0;
}
</style>
