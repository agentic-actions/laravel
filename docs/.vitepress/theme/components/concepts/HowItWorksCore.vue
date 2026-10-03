<script setup lang="ts">
/**
 * HowItWorksCore: the centre of the "every caller" Hub. The class, its effect,
 * and the one pipeline every caller goes through, in the README's order
 * (README.md, the opening paragraph; docs/concepts.md, "The pipeline").
 *
 * The steps are an ordered list of small monospace chips that wrap; tenant
 * membership and handle() carry the tenant and pass accents as on the home page.
 */
import type { Tone } from '../diagrams/tones'

const steps: { label: string; tone?: Tone }[] = [
  { label: 'exposure' },
  { label: 'token abilities' },
  { label: 'tenant membership', tone: 'tenant' },
  { label: 'authorize()' },
  { label: 'validation' },
  { label: 'handle()', tone: 'pass' },
  { label: 'output allowlist' },
]
</script>

<template>
  <Card eyebrow="action" title="CreatePost" mono status="Effect::Write" class="hiw-core">
    <span class="hiw-core__label">one pipeline, whoever calls</span>
    <ol class="hiw-core__steps" aria-label="The pipeline every caller goes through">
      <li v-for="step in steps" :key="step.label" class="hiw-core__step" :class="`aa-tone-${step.tone ?? 'neutral'}`">
        <span v-if="step.tone" class="hiw-core__dot" aria-hidden="true" />{{ step.label }}
      </li>
    </ol>
  </Card>
</template>

<style>
.hiw-core__label {
  display: block;
  margin: 8px 0 8px;
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
}

.hiw-core__steps,
.vp-doc .hiw-core__steps {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 6px;
  margin: 0;
  padding: 0;
  list-style: none;
  counter-reset: hiw-step;
}

.vp-doc .hiw-core__steps > li + li {
  margin-top: 0;
}

.hiw-core__step {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 8px;
  border: 1px solid var(--aa-line-strong);
  border-radius: var(--aa-radius-sm);
  background: var(--aa-sunken);
  color: var(--aa-ink);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  line-height: 18px;
  counter-increment: hiw-step;
}

.hiw-core__step::before {
  content: counter(hiw-step, decimal-leading-zero);
  color: var(--aa-muted);
}

.hiw-core__step:not(.aa-tone-neutral) {
  border-color: var(--tone-line);
  background: var(--tone-fill);
}

.hiw-core__step:not(.aa-tone-neutral)::before {
  color: var(--tone);
}

.hiw-core__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--tone);
}
</style>
