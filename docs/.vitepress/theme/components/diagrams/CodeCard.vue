<script setup lang="ts">
/**
 * CodeCard: a card with a file tab, whose body is the fenced code block placed
 * in its slot, highlighted by the site's Shiki themes like any other block.
 *
 * Use it where code is part of a picture: the action at a Hub's centre, the
 * two sides of a before-and-after. Elsewhere a plain fenced block is enough.
 * Leave a blank line after the opening tag and before the closing one.
 *
 * Props: file (the tab's label, such as "app/Actions/CreatePost.php"), tone
 * (tints the border, rarely needed).
 *
 *   <CodeCard file="app/Actions/CreatePost.php">
 *
 *   ```php
 *   protected ?Effect $effect = Effect::Write;
 *   ```
 *
 *   </CodeCard>
 */
import Icon from './Icon.vue'
import { toneClass, type Tone } from './tones'

withDefaults(defineProps<{ file?: string; tone?: Tone }>(), { tone: 'neutral' })
</script>

<template>
  <div class="aa-codecard" :class="toneClass(tone)">
    <div v-if="file" class="aa-codecard__tab">
      <Icon name="file" :size="15" />
      <span>{{ file }}</span>
    </div>
    <div class="vp-doc aa-codecard__body">
      <slot />
    </div>
  </div>
</template>

<style>
.aa-codecard {
  min-width: 0;
  overflow: hidden;
  border: 1px solid var(--aa-line-strong);
  border-radius: var(--aa-radius-lg);
  background: var(--aa-surface);
  box-shadow: var(--aa-shadow);
  text-align: left;
}

.aa-codecard:not(.aa-tone-neutral) {
  border-color: var(--tone-line);
}

.aa-codecard__tab {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  border-bottom: 1px solid var(--aa-line);
  color: var(--aa-ink-2);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
}

.aa-codecard__tab .aa-icon {
  color: var(--aa-muted);
}

.aa-codecard__body.vp-doc div[class*='language-'] {
  margin: 0;
  border: 0;
  border-radius: 0;
  background: transparent;
}

.aa-codecard__body.vp-doc div[class*='language-'] > span.lang {
  display: none;
}

.aa-codecard__body.vp-doc div[class*='language-'] pre {
  padding: 16px 0;
}

.aa-codecard__body.vp-doc div[class*='language-'] code {
  font-size: var(--aa-fs-sm);
}

.aa-codecard__body.vp-doc > p {
  margin: 0;
}
</style>
