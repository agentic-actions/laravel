<script setup lang="ts">
/**
 * Columns: two or three things side by side, stacked when the container is
 * narrower than 560px; optionally with an arrow between them.
 *
 * Use it for a before-and-after, a request beside its answer, a screen beside
 * the code behind it. Children are any components: Card, Window, CodeCard.
 *
 * Props: cols (2 or 3, default 2), arrows (a dotted arrow between columns,
 * pointing down when stacked), align ("start" or "center", default center).
 *
 *   <Columns arrows>
 *   <Card title="Request" />
 *   <Card title="Response" />
 *   </Columns>
 */
withDefaults(defineProps<{ cols?: 2 | 3; arrows?: boolean; align?: 'start' | 'center' }>(), {
  cols: 2,
  arrows: false,
  align: 'center',
})
</script>

<template>
  <div class="aa-columns-c">
    <div class="aa-columns" :class="[`is-${cols}`, `is-${align}`, { 'has-arrows': arrows }]">
      <slot />
    </div>
  </div>
</template>

<style>
.aa-columns-c {
  container-type: inline-size;
}

.aa-columns {
  display: grid;
  gap: 20px;
}

.aa-columns.is-2 {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.aa-columns.is-3 {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.aa-columns.is-center {
  align-items: center;
}

.aa-columns.is-start {
  align-items: start;
}

.aa-columns.has-arrows {
  gap: 40px;
}

.aa-columns.has-arrows > * {
  position: relative;
}

.aa-columns.has-arrows > *:not(:last-child)::after {
  content: '';
  position: absolute;
  left: calc(100% + 8px);
  top: 50%;
  width: 24px;
  border-top: 1.5px dotted var(--aa-wire);
}

.aa-columns.has-arrows > *:not(:last-child)::before {
  content: '';
  position: absolute;
  left: calc(100% + 26px);
  top: calc(50% - 3px);
  width: 6px;
  height: 6px;
  border-top: 1.5px solid var(--aa-wire);
  border-right: 1.5px solid var(--aa-wire);
  transform: rotate(45deg);
}

@container (max-width: 560px) {
  .aa-columns.is-2,
  .aa-columns.is-3 {
    grid-template-columns: minmax(0, 1fr);
  }

  .aa-columns.has-arrows > *:not(:last-child)::after {
    left: 50%;
    top: calc(100% + 8px);
    width: 0;
    height: 24px;
    border-top: 0;
    border-left: 1.5px dotted var(--aa-wire);
  }

  .aa-columns.has-arrows > *:not(:last-child)::before {
    left: calc(50% - 3px);
    top: calc(100% + 26px);
    transform: rotate(135deg);
  }
}
</style>
