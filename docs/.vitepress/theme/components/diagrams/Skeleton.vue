<script setup lang="ts">
/**
 * Skeleton: grey bars standing for text a mock does not need to spell out,
 * such as the body of a message or the rest of a page. Decorative: a screen
 * reader skips it.
 *
 * Props: lines (default 1), width (the last line's width, default "60%").
 *
 *   <Skeleton :lines="2" width="40%" />
 */
withDefaults(defineProps<{ lines?: number; width?: string }>(), { lines: 1, width: '60%' })
</script>

<template>
  <span class="aa-skeleton" aria-hidden="true">
    <i v-for="n in lines" :key="n" :style="n === lines ? { width } : undefined" />
  </span>
</template>

<style>
.aa-skeleton {
  display: flex;
  flex-direction: column;
  gap: 6px;
  width: 100%;
}

.aa-skeleton i {
  display: block;
  height: 8px;
  border-radius: 4px;
  background: var(--aa-line);
}
</style>
