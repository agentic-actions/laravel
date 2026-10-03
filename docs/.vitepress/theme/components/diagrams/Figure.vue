<script setup lang="ts">
/**
 * Figure: the frame around every diagram or UI mock, on a faint dotted ground,
 * with a short caption under it.
 *
 * Use one per illustration, and put a Flow, Hub, Matrix, Window, Columns or
 * CodeCard inside. The caption says in one sentence what the picture shows;
 * the #caption slot takes inline code or a link.
 *
 * Props: caption, plain (no frame and no dotted ground), center (centre the
 * content, for a single Window or Card).
 *
 *   <Figure caption="A stranger gets the same 404 as a team that does not exist.">
 *   <MockTenants />
 *   </Figure>
 */
withDefaults(defineProps<{ caption?: string; plain?: boolean; center?: boolean }>(), { plain: false, center: false })
</script>

<template>
  <figure class="aa-figure" :class="{ 'is-plain': plain, 'is-center': center }">
    <div class="aa-figure__body">
      <slot />
    </div>
    <figcaption v-if="caption || $slots.caption" class="aa-figure__caption">
      <slot name="caption">{{ caption }}</slot>
    </figcaption>
  </figure>
</template>

<style>
.aa-figure {
  margin: 28px 0;
  container-type: inline-size;
}

.aa-figure__body {
  position: relative;
  padding: 28px;
  border: 1px solid var(--aa-line);
  border-radius: var(--aa-radius-lg);
  background-color: var(--aa-ground);
  background-image: radial-gradient(var(--aa-grid-dot) 1px, transparent 1.2px);
  background-size: 16px 16px;
  background-position: -8px -8px;
  color: var(--aa-ink);
  font-size: var(--aa-fs-sm);
  line-height: 1.5;
}

.aa-figure.is-plain .aa-figure__body {
  padding: 0;
  border: 0;
  background: none;
}

.aa-figure.is-center .aa-figure__body {
  display: flex;
  flex-direction: column;
  align-items: center;
}

.aa-figure__caption {
  margin-top: 10px;
  color: var(--aa-muted);
  font-size: var(--aa-fs-sm);
  line-height: 1.5;
}

@container (max-width: 520px) {
  .aa-figure__body {
    padding: 16px;
  }
}

.vp-doc .aa-figure p {
  margin: 0;
}
</style>
