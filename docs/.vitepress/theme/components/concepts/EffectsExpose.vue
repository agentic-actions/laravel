<script setup lang="ts">
/**
 * EffectsExpose: the forms of #[Expose] from docs/concepts.md#exposure, one
 * per row, each with the three surfaces it opens (the generated route, agents,
 * MCP) lit or dashed, and a last row for a class without the attribute, which
 * only discovery reaches (src/Exposure/ExposureRule.php: Console is always
 * open, no #[Expose] opens nothing else).
 *
 * The chips say "open" or "closed" to a screen reader. Below 560px the chips
 * move under the code.
 */
import type { Tone } from '../diagrams/tones'

type Slot = 'route' | 'agents' | 'mcp'

interface Form {
  code: string
  note: string
  opens: Slot[]
  toolset?: string
  none?: boolean
}

const surfaces: { slot: Slot; label: string; tone: Tone }[] = [
  { slot: 'route', label: 'Route', tone: 'neutral' },
  { slot: 'agents', label: 'Agents', tone: 'agent' },
  { slot: 'mcp', label: 'MCP', tone: 'mcp' },
]

const forms: Form[] = [
  { code: '#[Expose]', note: 'every surface the effect and shape allow', opens: ['route', 'agents', 'mcp'] },
  { code: '#[Expose(web: true)]', note: 'the generated route only', opens: ['route'] },
  { code: "#[Expose(agents: ['support'])]", note: 'the "support" toolset only', opens: ['agents'], toolset: 'support' },
  { code: "#[Expose(web: true, agents: ['default'])]", note: 'both', opens: ['route', 'agents'], toolset: 'default' },
  { code: '#[Expose(mcp: true)]', note: 'MCP only', opens: ['mcp'] },
  { code: 'no #[Expose]', note: 'run(), attempt() and actions:run still reach it', opens: [], none: true },
]
</script>

<template>
  <div class="aa-fx-expose-c">
    <div class="aa-fx-expose">
      <div class="aa-fx-expose__head" aria-hidden="true">
        <span>The attribute</span>
        <span>Opens</span>
      </div>
      <ul class="aa-fx-expose__rows">
        <li v-for="form in forms" :key="form.code" class="aa-fx-expose__row" :class="{ 'is-none': form.none }">
          <span class="aa-fx-expose__code">
            <code>{{ form.code }}</code>
            <span class="aa-fx-expose__note">{{ form.note }}</span>
          </span>
          <ul class="aa-fx-expose__chips" :aria-label="`${form.code} opens`">
            <li
              v-for="surface in surfaces"
              :key="surface.slot"
              class="aa-fx-expose__chip"
              :class="form.opens.includes(surface.slot) ? ['is-on', `aa-tone-${surface.tone}`] : 'is-off'"
            >
              <span v-if="form.opens.includes(surface.slot)" class="aa-fx-expose__dot" aria-hidden="true" />
              <span>{{ surface.slot === 'agents' && form.toolset && form.opens.includes('agents') ? `Agents: ${form.toolset}` : surface.label }}</span>
              <span class="aa-fx-expose__sr">{{ form.opens.includes(surface.slot) ? ': open' : ': closed' }}</span>
            </li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</template>

<style>
.aa-fx-expose-c {
  container-type: inline-size;
}

.aa-fx-expose {
  overflow: hidden;
  border: 1px solid var(--aa-line-strong);
  border-radius: var(--aa-radius-lg);
  background: var(--aa-surface);
  box-shadow: var(--aa-shadow);
}

.aa-fx-expose__head {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  padding: 9px 16px;
  border-bottom: 1px solid var(--aa-line);
  background: var(--aa-sunken);
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  letter-spacing: var(--aa-tracking-label);
  text-transform: uppercase;
}

.aa-fx-expose__rows,
.vp-doc .aa-fx-expose__rows {
  margin: 0;
  padding: 0;
  list-style: none;
}

.vp-doc .aa-fx-expose__rows li + li,
.vp-doc .aa-fx-expose__chips li + li {
  margin-top: 0;
}

.aa-fx-expose__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 8px 16px;
  padding: 12px 16px;
}

.aa-fx-expose__row + .aa-fx-expose__row {
  border-top: 1px solid var(--aa-line);
}

.aa-fx-expose__row.is-none {
  background: var(--aa-ground);
}

.aa-fx-expose__code {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.aa-fx-expose__code code,
.vp-doc .aa-fx-expose__code code {
  padding: 0;
  background: none;
  color: var(--aa-ink);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-sm);
  overflow-wrap: anywhere;
}

.aa-fx-expose__row.is-none code {
  color: var(--aa-muted);
}

.aa-fx-expose__note {
  color: var(--aa-muted);
  font-size: var(--aa-fs-xs);
  line-height: 1.4;
}

.aa-fx-expose__chips,
.vp-doc .aa-fx-expose__chips {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 6px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.aa-fx-expose__chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 2px 9px;
  border: 1px solid var(--aa-line-strong);
  border-radius: 999px;
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  line-height: 18px;
  white-space: nowrap;
}

.aa-fx-expose__chip.is-on {
  border-color: var(--tone-line);
  background: var(--tone-fill);
  color: var(--tone);
}

.aa-fx-expose__chip.is-on.aa-tone-neutral {
  border-color: var(--aa-wire);
  background: var(--aa-sunken);
  color: var(--aa-ink);
}

.aa-fx-expose__chip.is-off {
  border-style: dashed;
  color: var(--aa-muted);
}

.aa-fx-expose__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--tone);
}

.aa-fx-expose__chip.aa-tone-neutral .aa-fx-expose__dot {
  background: var(--aa-ink);
}

.aa-fx-expose__sr {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

@container (max-width: 560px) {
  .aa-fx-expose__head span + span {
    display: none;
  }

  .aa-fx-expose__row {
    grid-template-columns: minmax(0, 1fr);
  }

  .aa-fx-expose__chips,
  .vp-doc .aa-fx-expose__chips {
    justify-content: flex-start;
  }
}
</style>
