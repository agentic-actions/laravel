<script setup lang="ts">
/**
 * HomeCta: the home page's closing band: one headline, one line, the buttons
 * and the install command again.
 */
</script>

<template>
  <section class="aa-cta aa-rules" aria-labelledby="aa-cta-title">
    <h2 id="aa-cta-title" class="aa-cta__title">Start with one action.</h2>
    <p class="aa-cta__lede">Install the package, write CreatePost, and call it from a form, the CLI and an agent.</p>
    <HomeActions secondary="concepts" />
  </section>
</template>

<style>
.aa-cta {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
  padding: clamp(64px, 9vw, 120px) var(--aa-home-pad);
  text-align: center;
}

.aa-cta__title {
  margin: 0;
  color: var(--aa-ink);
  font-size: var(--aa-fs-2xl);
  font-weight: 500;
  letter-spacing: -0.025em;
}

.aa-cta__lede {
  max-width: 560px;
  margin: 0 0 12px;
  color: var(--aa-ink-2);
  font-size: var(--aa-fs-base);
  line-height: 1.6;
}
</style>
