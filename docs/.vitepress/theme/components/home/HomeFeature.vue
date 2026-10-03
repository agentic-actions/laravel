<script setup lang="ts">
/**
 * HomeFeature: one cell of HomeFeatures: an illustration (the slot, usually a
 * Mock* component) on a dotted plate, a title and two lines, the whole cell a
 * link to the concept page.
 *
 * Props: title, text, link.
 */
import { withBase } from 'vitepress'

defineProps<{ title: string; text: string; link: string }>()
</script>

<template>
  <li class="aa-feature">
    <div class="aa-feature__plate">
      <div class="aa-feature__art"><slot /></div>
    </div>
    <div class="aa-feature__text">
      <h3 class="aa-feature__title">
        <a :href="withBase(link)">{{ title }}</a>
      </h3>
      <p>{{ text }}</p>
    </div>
    <span class="aa-feature__go" aria-hidden="true"><Icon name="arrow" :size="16" /></span>
  </li>
</template>

<style>
.aa-feature {
  position: relative;
  display: grid;
  grid-row: span 2;
  grid-template-rows: subgrid;
  row-gap: 20px;
  padding: 24px 24px 28px;
  background: var(--aa-ground);
  transition: background-color 0.15s;
}

.aa-feature:hover {
  background: var(--aa-sunken);
}

.aa-feature__plate {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 300px;
  padding: 20px 16px;
  border: 1px dashed var(--aa-line-strong);
  border-radius: var(--aa-radius);
  background-color: var(--aa-ground);
  background-image: radial-gradient(var(--aa-grid-dot) 1px, transparent 1.2px);
  background-size: 14px 14px;
}

.aa-feature__art {
  width: 100%;
  display: flex;
  justify-content: center;
}

.aa-feature__text {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding-right: 40px;
}

.aa-feature__title {
  margin: 0;
  font-size: var(--aa-fs-lg);
  font-weight: 500;
  letter-spacing: -0.01em;
}

.aa-feature__title a {
  color: var(--aa-ink);
  text-decoration: none;
}

/* The whole cell is the link. */
.aa-feature__title a::after {
  content: '';
  position: absolute;
  inset: 0;
}

.aa-feature__title a:focus-visible {
  outline: none;
}

.aa-feature:has(a:focus-visible) {
  outline: 2px solid var(--aa-ink);
  outline-offset: -4px;
}

.aa-feature__text p {
  margin: 0;
  color: var(--aa-ink-2);
  font-size: var(--aa-fs-md);
  line-height: 1.55;
}

.aa-feature__go {
  position: absolute;
  right: 24px;
  bottom: 28px;
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border: 1px solid var(--aa-line-strong);
  border-radius: var(--aa-radius-sm);
  background: var(--aa-surface);
  color: var(--aa-ink);
}

.aa-feature:hover .aa-feature__go {
  border-color: var(--aa-ink);
}

@media (max-width: 639px) {
  .aa-feature__plate {
    min-height: 0;
  }
}
</style>
