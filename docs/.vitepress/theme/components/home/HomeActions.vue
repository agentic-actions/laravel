<script setup lang="ts">
/**
 * HomeActions: the home page's two buttons (Get started, then the hosted
 * demo, GitHub or How it works) and the install command with a copy button.
 * The command comes from the config's themeConfig.install, which follows
 * js/package.json's version.
 */
import { ref } from 'vue'
import { useData, withBase } from 'vitepress'

const props = withDefaults(defineProps<{ secondary?: 'demo' | 'github' | 'concepts' }>(), { secondary: 'demo' })

const { theme } = useData()
const copied = ref(false)

async function copy(): Promise<void> {
  try {
    await navigator.clipboard.writeText(theme.value.install)
    copied.value = true
    setTimeout(() => (copied.value = false), 1600)
  } catch {
    copied.value = false
  }
}

const second = {
  demo: { text: 'Try the demo', href: 'https://demo.agentic-actions.com', external: true },
  github: { text: 'GitHub', href: 'https://github.com/agentic-actions/laravel', external: true },
  concepts: { text: 'How it works', href: withBase('/how-it-works/one-action'), external: false },
}[props.secondary]
</script>

<template>
  <div class="aa-actions">
    <div class="aa-actions__buttons">
      <a class="aa-button is-primary" :href="withBase('/getting-started')">Get started</a>
      <a
        class="aa-button"
        :href="second.href"
        :target="second.external ? '_blank' : undefined"
        :rel="second.external ? 'noreferrer' : undefined"
      >
        {{ second.text }}<span v-if="second.external" class="aa-button__arrow" aria-hidden="true">↗</span>
      </a>
    </div>
    <div class="aa-install">
      <code><span class="aa-install__prompt" aria-hidden="true">$</span> {{ theme.install }}</code>
      <button type="button" class="aa-install__copy" :aria-label="copied ? 'Copied' : 'Copy the install command'" @click="copy">
        <Icon :name="copied ? 'check' : 'copy'" :size="16" />
      </button>
      <span class="aa-visually-hidden" aria-live="polite">{{ copied ? 'Copied' : '' }}</span>
    </div>
  </div>
</template>

<style>
.aa-actions {
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 22px;
}

.aa-actions__buttons {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 12px;
}

.aa-button {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 40px;
  padding: 0 18px;
  border: 1px solid var(--aa-ink);
  border-radius: var(--aa-radius-sm);
  background: var(--aa-surface);
  color: var(--aa-ink);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-sm);
  letter-spacing: var(--aa-tracking-label);
  text-decoration: none;
  text-transform: uppercase;
  transition: background-color 0.15s, color 0.15s;
}

.aa-button:hover {
  background: var(--aa-sunken);
}

.aa-button.is-primary {
  background: var(--aa-ink);
  color: var(--aa-surface);
}

.aa-button.is-primary:hover {
  background: var(--aa-ink-2);
  border-color: var(--aa-ink-2);
}

.aa-install {
  display: flex;
  align-items: center;
  max-width: 100%;
  border: 1px solid var(--aa-line-strong);
  border-radius: var(--aa-radius-sm);
  background: var(--aa-surface);
}

.aa-install code {
  min-width: 0;
  padding: 9px 4px 9px 14px;
  overflow-x: auto;
  background: none;
  color: var(--aa-ink-2);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-sm);
  white-space: nowrap;
}

@media (max-width: 479px) {
  .aa-install code {
    overflow-wrap: anywhere;
    white-space: normal;
  }
}

.aa-install__prompt {
  color: var(--aa-muted);
}

.aa-install__copy {
  display: grid;
  flex: none;
  place-items: center;
  width: 40px;
  height: 38px;
  color: var(--aa-muted);
  cursor: pointer;
}

.aa-install__copy:hover {
  color: var(--aa-ink);
}
</style>
