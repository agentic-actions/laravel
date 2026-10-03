<script setup lang="ts">
/**
 * HomeHero: the home page's first band: a short headline, one line under it,
 * the buttons and the install command, over a faint halftone glow (CSS only,
 * decorative).
 */
import { useData, withBase } from 'vitepress'

const { theme } = useData()
</script>

<template>
  <section class="aa-hero aa-rules" aria-labelledby="aa-hero-title">
    <div class="aa-hero__glow" aria-hidden="true" />
    <div class="aa-hero__inner">
      <a class="aa-hero__badge" :href="withBase('/changelog')">Laravel package · {{ theme.release }}</a>
      <h1 id="aa-hero-title" class="aa-hero__title">Write each Laravel operation once.</h1>
      <p class="aa-hero__lede">
        One Action class answers your web forms, JSON, Artisan, the queue, TypeScript, laravel/ai agents and MCP clients, each
        through the same checked pipeline.
      </p>
      <HomeActions />
    </div>
  </section>
</template>

<style>
.aa-hero {
  position: relative;
  overflow: hidden;
  padding: clamp(72px, 12vw, 150px) var(--aa-home-pad) clamp(64px, 10vw, 130px);
  text-align: center;
}

.aa-hero__glow {
  position: absolute;
  inset: -10% -5%;
  background-image: radial-gradient(circle, var(--aa-halftone) 1px, transparent 1.5px);
  background-size: 6px 6px;
  mask-image: radial-gradient(ellipse 46% 50% at 50% 48%, #000 0%, rgba(0, 0, 0, 0.45) 45%, transparent 100%);
  -webkit-mask-image: radial-gradient(ellipse 46% 50% at 50% 48%, #000 0%, rgba(0, 0, 0, 0.45) 45%, transparent 100%);
  pointer-events: none;
}

.aa-hero__inner {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 22px;
  max-width: 860px;
  margin: 0 auto;
}

.aa-hero__badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 3px 12px;
  border: 1px solid var(--aa-line-strong);
  border-radius: 999px;
  background: var(--aa-surface);
  color: var(--aa-ink-2);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  text-decoration: none;
}

.aa-hero__badge:hover {
  color: var(--aa-ink);
}

.aa-hero__title {
  margin: 0;
  color: var(--aa-ink);
  font-size: var(--aa-fs-display);
  font-weight: 500;
  letter-spacing: -0.035em;
  line-height: 1.04;
  text-wrap: balance;
}

.aa-hero__lede {
  max-width: 620px;
  margin: 0 0 6px;
  color: var(--aa-ink-2);
  font-size: var(--aa-fs-lg);
  line-height: 1.55;
  text-wrap: balance;
}
</style>
