<script setup lang="ts">
/**
 * Step: one box in a Flow, with a title, an optional note under it, and an
 * optional branch: the way out when this step says no, such as
 * "refused: 404", drawn under the box (beside it when the flow stacks, and
 * under it again, on the right, when the flow is narrower than 520px).
 *
 * Use it only inside a Flow. Keep the title to a few words; the note to one
 * short line.
 *
 * Props: title, note, tone (neutral, pass, refuse, wait, agent, mcp, tenant),
 * code (the title in the monospace, for a method or a route such as
 * "authorize()"), icon (an Icon name), branch (the branch's text) and
 * branchTone (default refuse).
 *
 *   <Step title="tenant membership" code tone="tenant" note="the actor belongs to the team" branch="not a member: 404" />
 */
import Icon from './Icon.vue'
import Pill from './Pill.vue'
import { toneClass, type Tone } from './tones'

withDefaults(
  defineProps<{ title: string; note?: string; tone?: Tone; code?: boolean; icon?: string; branch?: string; branchTone?: Tone }>(),
  { tone: 'neutral', code: false, branchTone: 'refuse' }
)
</script>

<template>
  <li class="aa-step" :class="[toneClass(tone), { 'has-branch': branch }]">
    <div class="aa-step__box">
      <span class="aa-step__title" :class="{ 'is-code': code }">
        <Icon v-if="icon" :name="icon" :size="16" class="aa-step__icon" />
        <span v-else-if="tone !== 'neutral'" class="aa-step__dot" aria-hidden="true" />
        <span>{{ title }}</span>
      </span>
      <span v-if="note" class="aa-step__note">{{ note }}</span>
      <slot />
    </div>
    <div v-if="branch" class="aa-step__branch" :class="toneClass(branchTone)">
      <span class="aa-step__wire" aria-hidden="true" />
      <Pill :tone="branchTone">{{ branch }}</Pill>
    </div>
  </li>
</template>

<style>
.aa-step {
  position: relative;
  display: grid;
  grid-row: span 2;
  grid-template-rows: subgrid;
  min-width: 0;
  counter-increment: aa-step;
}

.aa-step__box {
  position: relative;
  display: flex;
  flex-direction: column;
  justify-content: flex-start;
  gap: 4px;
  min-width: 0;
  padding: 12px 14px;
  border: 1px solid var(--aa-line-strong);
  border-radius: var(--aa-radius);
  background: var(--aa-surface);
  box-shadow: var(--aa-shadow);
}

.aa-step:not(.aa-tone-neutral) .aa-step__box {
  border-color: var(--tone-line);
}

.aa-step__title {
  display: flex;
  align-items: center;
  gap: 7px;
  color: var(--aa-ink);
  font-size: var(--aa-fs-md);
  font-weight: 500;
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.aa-step__title.is-code {
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-sm);
  font-weight: 400;
}

.aa-flow.is-numbered .aa-step__title::before {
  content: counter(aa-step, decimal-leading-zero);
  flex: none;
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  font-weight: 400;
}

.aa-step__icon {
  color: var(--tone);
}

.aa-step.aa-tone-neutral .aa-step__icon {
  color: var(--aa-muted);
}

.aa-step__dot {
  flex: none;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--tone);
}

.aa-step__note {
  color: var(--aa-muted);
  font-size: var(--aa-fs-sm);
  line-height: 1.4;
}

.aa-flow.is-compact .aa-step__box {
  padding: 10px 11px;
}

.aa-flow.is-compact .aa-step__title {
  font-size: var(--aa-fs-sm);
}

.aa-flow.is-compact .aa-step__note {
  font-size: var(--aa-fs-xs);
}

/* The arrow to the next step: a dotted wire and a head, in the gap. */
.aa-step:not(:last-child) .aa-step__box::after,
.aa-step:not(:last-child) .aa-step__box::before {
  content: '';
  position: absolute;
  pointer-events: none;
}

.aa-step:not(:last-child) .aa-step__box::after {
  left: calc(100% + 1px);
  top: 50%;
  width: calc(var(--aa-flow-gap) - 3px);
  border-top: 1.5px dotted var(--aa-wire);
}

.aa-step:not(:last-child) .aa-step__box::before {
  left: calc(100% + var(--aa-flow-gap) - 7px);
  top: calc(50% - 3px);
  width: 6px;
  height: 6px;
  border-top: 1.5px solid var(--aa-wire);
  border-right: 1.5px solid var(--aa-wire);
  transform: rotate(45deg);
}

/* The branch: a short wire down to a pill. */
.aa-step__branch {
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 0;
}

.aa-step__wire {
  width: 0;
  height: 16px;
  border-left: 1.5px dotted var(--tone);
}

.aa-step__branch .aa-pill {
  max-width: none;
  white-space: nowrap;
}

/* Stacked: arrows point down, and a branch sits beside its box. Every box
   takes the flow's first column, so the boxes share one width. */
@container (max-width: 640px) {
  .aa-step {
    grid-row: auto;
    grid-column: 1 / -1;
    grid-template-rows: none;
    grid-template-columns: subgrid;
    align-items: center;
  }

  .aa-step__box {
    grid-column: 1;
  }

  .aa-step:not(:last-child) .aa-step__box::after {
    left: 50%;
    top: calc(100% + 1px);
    width: 0;
    height: calc(var(--aa-flow-gap) - 3px);
    border-top: 0;
    border-left: 1.5px dotted var(--aa-wire);
  }

  .aa-step:not(:last-child) .aa-step__box::before {
    left: calc(50% - 3px);
    top: calc(100% + var(--aa-flow-gap) - 7px);
    transform: rotate(135deg);
  }

  .aa-step__branch {
    grid-column: 2;
    flex-direction: row;
    max-width: 46cqw;
  }

  .aa-step__wire {
    width: 14px;
    height: 0;
    border-left: 0;
    border-top: 1.5px dotted var(--tone);
  }

  .aa-step__branch .aa-pill {
    white-space: normal;
  }
}

.aa-flow.is-vertical > .aa-step {
  grid-row: auto;
  grid-column: 1 / -1;
  grid-template-rows: none;
  grid-template-columns: subgrid;
  align-items: center;
}

.aa-flow.is-vertical .aa-step__box {
  grid-column: 1;
}

.aa-flow.is-vertical > .aa-step:not(:last-child) .aa-step__box::after {
  left: 50%;
  top: calc(100% + 1px);
  width: 0;
  height: calc(var(--aa-flow-gap) - 3px);
  border-top: 0;
  border-left: 1.5px dotted var(--aa-wire);
}

.aa-flow.is-vertical > .aa-step:not(:last-child) .aa-step__box::before {
  left: calc(50% - 3px);
  top: calc(100% + var(--aa-flow-gap) - 7px);
  transform: rotate(135deg);
}

.aa-flow.is-vertical .aa-step__branch {
  grid-column: 2;
  flex-direction: row;
  max-width: 46cqw;
}

.aa-flow.is-vertical .aa-step__wire {
  width: 14px;
  height: 0;
  border-left: 0;
  border-top: 1.5px dotted var(--tone);
}

.aa-flow.is-vertical .aa-step__branch .aa-pill {
  white-space: normal;
}

/* Narrow (a phone): every box takes the whole width and its answer sits under
   it, on the right, so neither a title nor an answer is squeezed. The arrow
   runs down the left, drawn on the step rather than the box, so it passes
   beside the answer instead of through it. */
@container (max-width: 520px) {
  .aa-flow > .aa-step.aa-step {
    isolation: isolate;
  }

  .aa-flow > .aa-step.aa-step .aa-step__box::after,
  .aa-flow > .aa-step.aa-step .aa-step__box::before {
    display: none;
  }

  .aa-flow > .aa-step.aa-step:not(:last-child)::after,
  .aa-flow > .aa-step.aa-step:not(:last-child)::before {
    content: '';
    position: absolute;
    pointer-events: none;
  }

  .aa-flow > .aa-step.aa-step:not(:last-child)::after {
    z-index: -1;
    left: 24px;
    top: 0;
    bottom: calc(3px - var(--aa-flow-gap));
    border-left: 1.5px dotted var(--aa-wire);
  }

  .aa-flow > .aa-step.aa-step:not(:last-child)::before {
    left: 21px;
    bottom: calc(4px - var(--aa-flow-gap));
    width: 6px;
    height: 6px;
    border-top: 1.5px solid var(--aa-wire);
    border-right: 1.5px solid var(--aa-wire);
    transform: rotate(135deg);
  }

  .aa-flow > .aa-step.aa-step .aa-step__branch {
    grid-column: 1;
    flex-direction: column;
    align-items: flex-end;
    max-width: none;
    padding-right: 14px;
  }

  .aa-flow > .aa-step.aa-step .aa-step__wire {
    width: 0;
    height: 10px;
    margin-right: 22px;
    border-top: 0;
    border-left: 1.5px dotted var(--tone);
  }

  .aa-flow > .aa-step.aa-step .aa-step__branch .aa-pill {
    max-width: 100%;
    white-space: normal;
  }
}
</style>
