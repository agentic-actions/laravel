<script setup lang="ts">
/**
 * HomeFrame: the home page's blueprint frame: a centred column with ruled
 * edges, inside which each HomeHero, HomeSection and HomeCta is a band
 * separated by a horizontal rule. Used by docs/site/index.md only.
 */
</script>

<template>
  <div class="aa-home">
    <slot />
  </div>
</template>

<style>
.aa-home {
  --aa-home-pad: clamp(16px, 4vw, 40px);
  position: relative;
  max-width: 1280px;
  margin: 0 auto;
  border-right: 1px solid var(--aa-line);
  border-left: 1px solid var(--aa-line);
  color: var(--aa-ink);
}

.aa-home > * + * {
  border-top: 1px solid var(--aa-line);
}

/* Two vertical column rules, at a third and two thirds, behind a band. */
.aa-rules {
  background-image:
    linear-gradient(var(--aa-line), var(--aa-line)),
    linear-gradient(var(--aa-line), var(--aa-line));
  background-size: 1px 100%, 1px 100%;
  background-position: 33.333% 0, 66.667% 0;
  background-repeat: no-repeat;
}

/* The VitePress home layout's own bottom margin; the frame ends at the footer. */
.VPHome:has(.aa-home) {
  margin-bottom: 0 !important;
}

@media (max-width: 767px) {
  .aa-home {
    border-right: 0;
    border-left: 0;
  }

  .aa-rules {
    background-image: none;
  }
}
</style>
