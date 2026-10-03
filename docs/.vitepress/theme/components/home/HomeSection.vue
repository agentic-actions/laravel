<script setup lang="ts">
/**
 * HomeSection: one band of the home page: a header (a monospace eyebrow, a
 * title and one or two lines) over the column rules, then the slot (a
 * diagram, a grid of features) below a horizontal rule.
 *
 * Props: id (the anchor), eyebrow, title, lede, flush (no padding around the
 * slot, for a grid that draws its own lines), more and moreLink (a link under
 * the slot to the page that explains it).
 */
import { withBase } from 'vitepress'

withDefaults(
  defineProps<{ id: string; eyebrow?: string; title: string; lede?: string; flush?: boolean; more?: string; moreLink?: string }>(),
  { flush: false }
)
</script>

<template>
  <section class="aa-section" :aria-labelledby="`${id}-title`">
    <header class="aa-section__head aa-rules">
      <span v-if="eyebrow" class="aa-section__eyebrow">{{ eyebrow }}</span>
      <h2 :id="`${id}-title`" class="aa-section__title">{{ title }}</h2>
      <p v-if="lede" class="aa-section__lede">{{ lede }}</p>
    </header>
    <div class="aa-section__body" :class="{ 'is-flush': flush }">
      <slot />
      <a v-if="more && moreLink" class="aa-section__more" :href="withBase(moreLink)">{{ more }} <span aria-hidden="true">→</span></a>
    </div>
  </section>
</template>

<style>
.aa-section__head {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: clamp(48px, 7vw, 88px) var(--aa-home-pad) clamp(32px, 4vw, 48px);
}

.aa-section__eyebrow {
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  letter-spacing: var(--aa-tracking-label);
  text-transform: uppercase;
}

.aa-section__title {
  max-width: 760px;
  margin: 0;
  color: var(--aa-ink);
  font-size: var(--aa-fs-2xl);
  font-weight: 500;
  letter-spacing: -0.025em;
  line-height: 1.15;
}

.aa-section__lede {
  max-width: 620px;
  margin: 0;
  color: var(--aa-ink-2);
  font-size: var(--aa-fs-base);
  line-height: 1.6;
}

.aa-section__body {
  padding: clamp(28px, 5vw, 64px) var(--aa-home-pad);
  border-top: 1px solid var(--aa-line);
  background-image: radial-gradient(var(--aa-grid-dot) 1px, transparent 1.2px);
  background-size: 16px 16px;
}

.aa-section__more {
  display: inline-block;
  margin-top: 28px;
  color: var(--aa-ink);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-sm);
  text-decoration: underline;
  text-decoration-color: var(--aa-line-strong);
  text-underline-offset: 5px;
}

.aa-section__more:hover {
  text-decoration-color: var(--aa-ink);
}

.aa-section__body.is-flush {
  padding: 0;
  background: none;
}
</style>
