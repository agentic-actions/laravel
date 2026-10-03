<script setup lang="ts">
/**
 * Icon: a small stroke icon in the current text colour, always decorative
 * (aria-hidden), so the words beside it carry the meaning.
 *
 * Use it inside a Hub caller, a Step, a Card title or a UI mock; never alone.
 *
 * Props: name (one of the keys below), size (px, default 20).
 *
 *   <Icon name="terminal" />
 */
import { computed } from 'vue'

const props = withDefaults(defineProps<{ name: string; size?: number }>(), { size: 20 })

const icons: Record<string, string> = {
  form: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h10M7 13h6"/><rect x="7" y="16" width="5" height="1.5" rx=".75"/>',
  json: '<path d="M9 4C7 4 6.5 5 6.5 6.5v2.8c0 1.2-.8 2.2-2.5 2.7 1.7.5 2.5 1.5 2.5 2.7v2.8C6.5 19 7 20 9 20M15 4c2 0 2.5 1 2.5 2.5v2.8c0 1.2.8 2.2 2.5 2.7-1.7.5-2.5 1.5-2.5 2.7v2.8c0 1.5-.5 2.5-2.5 2.5"/>',
  terminal: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9.5l3 2.5-3 2.5M12.5 15H17"/>',
  queue: '<path d="M12 4l8 4-8 4-8-4z"/><path d="M4 12l8 4 8-4M4 16l8 4 8-4"/>',
  ts: '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M7 10.5h6M10 10.5V18M18.2 11.2c-.5-.6-1.2-.9-2-.9-1.2 0-2 .6-2 1.6 0 2.3 4 1.3 4 3.8 0 1-.9 1.8-2.1 1.8-.9 0-1.7-.4-2.2-1"/>',
  agent: '<path d="M11 3.5l1.9 5.1 5.1 1.9-5.1 1.9L11 17.5l-1.9-5.1L4 10.5l5.1-1.9z"/><path d="M18 15.5l.8 1.7 1.7.8-1.7.8-.8 1.7-.8-1.7-1.7-.8 1.7-.8z"/>',
  mcp: '<path d="M9 3v5M15 3v5M7 8h10v3a5 5 0 0 1-10 0zM12 16v5"/>',
  user: '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c1-3.5 3.8-5 7-5s6 1.5 7 5"/>',
  team: '<circle cx="9" cy="9" r="3"/><circle cx="16.5" cy="10" r="2.5"/><path d="M3.5 19c.8-3 3-4.5 5.5-4.5s4.7 1.5 5.5 4.5M15.2 14.6c2.4-.3 4.5 1 5.2 3.9"/>',
  lock: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
  key: '<circle cx="8" cy="15" r="4"/><path d="M11 12l8-8M16 7l2 2M14 9l2 2"/>',
  table: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M9 10v9"/>',
  chart: '<path d="M4 20h16M7 16v-4M12 16V8M17 16v-6"/>',
  check: '<path d="M5 12.5l4.5 4.5L19 7"/>',
  x: '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>',
  clock: '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>',
  code: '<path d="M9 8l-4 4 4 4M15 8l4 4-4 4"/>',
  chat: '<path d="M5 5h14a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1h-8l-4 3v-3H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z"/>',
  globe: '<circle cx="12" cy="12" r="8"/><path d="M4 12h16M12 4c2.5 2.5 2.5 13.5 0 16M12 4c-2.5 2.5-2.5 13.5 0 16"/>',
  arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  external: '<path d="M7 17L17 7M9 7h8v8"/>',
  copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
  file: '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/>',
  shield: '<path d="M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6z"/>',
}

const markup = computed(() => icons[props.name] ?? '')
</script>

<template>
  <svg
    class="aa-icon"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.6"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
    v-html="markup"
  />
</template>

<style>
.aa-icon {
  flex: none;
  display: inline-block;
  vertical-align: middle;
}
</style>
