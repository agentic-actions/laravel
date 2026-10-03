<script setup lang="ts">
/**
 * Hub: one thing in the centre (a CodeCard or a Card) and the callers that
 * reach it around it, each with a dotted wire into the centre. The first half
 * of the callers sit on the left, the rest on the right; when the container is
 * narrower than 1080px they stack above and below the centre as tiles, since
 * a caller beside a wide centre needs about 260px.
 *
 * Use it for "many callers, one target": the surfaces of one action, the
 * clients of one MCP server. A screen reader reads the left callers, the
 * centre, then the right callers.
 *
 * Props: callers, a list of { label, detail?, icon?, tone? } (detail is a short
 * monospace line such as a route or a command; icon an Icon name), label (the
 * group's accessible name), split (how many callers go left; default half,
 * rounded up).
 *
 *   <Hub label="Who calls CreatePost" :callers="[
 *     { label: 'Artisan', detail: 'actions:run create-post', icon: 'terminal' },
 *     { label: 'MCP clients', detail: 'tool create-post', icon: 'mcp', tone: 'mcp' },
 *   ]">
 *   <Card title="CreatePost" mono />
 *   </Hub>
 */
import { computed } from 'vue'
import Icon from './Icon.vue'
import { toneClass, type Tone } from './tones'

interface Caller {
  label: string
  detail?: string
  icon?: string
  tone?: Tone
}

const props = defineProps<{ callers: Caller[]; label?: string; split?: number }>()

const cut = computed(() => props.split ?? Math.ceil(props.callers.length / 2))
const left = computed(() => props.callers.slice(0, cut.value))
const right = computed(() => props.callers.slice(cut.value))
</script>

<template>
  <div class="aa-hub-c">
    <div class="aa-hub" role="group" :aria-label="label">
      <ul class="aa-hub__side is-left">
        <li v-for="caller in left" :key="caller.label" class="aa-hub__caller" :class="toneClass(caller.tone)">
          <span class="aa-hub__text">
            <span class="aa-hub__label">{{ caller.label }}</span>
            <span v-if="caller.detail" class="aa-hub__detail">{{ caller.detail }}</span>
          </span>
          <span v-if="caller.icon" class="aa-hub__tile"><Icon :name="caller.icon" /></span>
          <span class="aa-hub__wire" aria-hidden="true" />
        </li>
      </ul>
      <div class="aa-hub__center">
        <slot />
      </div>
      <ul v-if="right.length" class="aa-hub__side is-right">
        <li v-for="caller in right" :key="caller.label" class="aa-hub__caller" :class="toneClass(caller.tone)">
          <span class="aa-hub__text">
            <span class="aa-hub__label">{{ caller.label }}</span>
            <span v-if="caller.detail" class="aa-hub__detail">{{ caller.detail }}</span>
          </span>
          <span v-if="caller.icon" class="aa-hub__tile"><Icon :name="caller.icon" /></span>
          <span class="aa-hub__wire" aria-hidden="true" />
        </li>
      </ul>
    </div>
  </div>
</template>

<style>
.aa-hub-c {
  container-type: inline-size;
}

.aa-hub {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, var(--aa-hub-center, 680px)) minmax(0, 1fr);
  align-items: stretch;
}

.aa-hub__side,
.vp-doc .aa-hub__side {
  display: flex;
  flex-direction: column;
  justify-content: space-around;
  gap: 18px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.vp-doc .aa-hub__side > li + li {
  margin-top: 0;
}

.aa-hub__caller {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.aa-hub__side.is-right .aa-hub__caller {
  flex-direction: row-reverse;
}

.aa-hub__text {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 2px;
  min-width: 0;
  text-align: right;
}

.aa-hub__side.is-right .aa-hub__text {
  align-items: flex-start;
  text-align: left;
}

.aa-hub__label {
  color: var(--aa-ink);
  font-size: var(--aa-fs-md);
  font-weight: 500;
  line-height: 1.3;
}

.aa-hub__detail {
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  line-height: 1.4;
}

.aa-hub__caller:not(.aa-tone-neutral) .aa-hub__detail {
  color: var(--tone);
}

.aa-hub__tile {
  flex: none;
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  border: 1px solid var(--aa-line-strong);
  border-radius: var(--aa-radius);
  background: var(--aa-surface);
  box-shadow: var(--aa-shadow);
  color: var(--aa-ink);
}

.aa-hub__caller:not(.aa-tone-neutral) .aa-hub__tile {
  border-color: var(--tone-line);
  color: var(--tone);
}

/* A dotted wire from the tile into the centre, with a head at the centre. */
.aa-hub__wire {
  position: relative;
  flex: 1 1 24px;
  min-width: 24px;
  border-top: 1.5px dotted var(--aa-wire);
}

.aa-hub__wire::after {
  content: '';
  position: absolute;
  top: -4.5px;
  right: 0;
  width: 6px;
  height: 6px;
  border-top: 1.5px solid var(--aa-wire);
  border-right: 1.5px solid var(--aa-wire);
  transform: rotate(45deg);
}

.aa-hub__side.is-right .aa-hub__wire::after {
  right: auto;
  left: 0;
  transform: rotate(-135deg);
}

.aa-hub__center {
  position: relative;
  display: flex;
  flex-direction: column;
  justify-content: center;
  min-width: 0;
}

/* Narrow: the callers become tiles above and below the centre. */
@container (max-width: 1080px) {
  .aa-hub {
    display: flex;
    flex-direction: column;
  }

  .aa-hub__side,
  .vp-doc .aa-hub__side {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 220px), 1fr));
    gap: 10px;
    position: relative;
  }

  .aa-hub__side.is-left {
    margin-bottom: 34px;
  }

  .aa-hub__side.is-right {
    margin-top: 34px;
  }

  .aa-hub__side::after {
    content: '';
    position: absolute;
    left: 50%;
    height: 26px;
    border-left: 1.5px dotted var(--aa-wire);
  }

  .aa-hub__side.is-left::after {
    top: calc(100% + 4px);
  }

  .aa-hub__side.is-right::after {
    bottom: calc(100% + 4px);
  }

  .aa-hub__caller,
  .aa-hub__side.is-right .aa-hub__caller {
    flex-direction: row-reverse;
    justify-content: flex-end;
    gap: 10px;
    padding: 8px 10px;
    border: 1px solid var(--aa-line);
    border-radius: var(--aa-radius);
    background: var(--aa-surface);
  }

  .aa-hub__caller:not(.aa-tone-neutral) {
    border-color: var(--tone-line);
  }

  .aa-hub__text,
  .aa-hub__side.is-right .aa-hub__text {
    align-items: flex-start;
    text-align: left;
  }

  .aa-hub__tile {
    width: 32px;
    height: 32px;
    border: 0;
    box-shadow: none;
    background: none;
  }

  .aa-hub__wire {
    display: none;
  }
}
</style>
