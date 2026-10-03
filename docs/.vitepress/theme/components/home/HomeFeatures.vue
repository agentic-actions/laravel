<script setup lang="ts">
/**
 * HomeFeatures: the grid of HomeFeature cells, three across, two on a tablet
 * and one on a phone, ruled by one-pixel lines.
 */
</script>

<template>
  <ul class="aa-features">
    <slot />
  </ul>
</template>

<style>
.aa-features {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  grid-auto-rows: auto;
  gap: 1px;
  margin: 0;
  padding: 0;
  background: var(--aa-line);
  list-style: none;
}

@media (max-width: 1023px) {
  .aa-features {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 639px) {
  .aa-features {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
