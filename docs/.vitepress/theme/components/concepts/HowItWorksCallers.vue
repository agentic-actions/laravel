<script setup lang="ts">
/**
 * HowItWorksCallers: what each caller of one action gets, as six cards in two
 * columns (one below 560px). Each card names the caller, what it calls, one
 * fact, and the one thing an app adds for it.
 *
 * Sources: README.md, "What that one class gets"; docs/concepts.md, "Context",
 * "Doors", "Exposure" and "Queued runs"; docs/mcp.md, "What a client sees" and
 * "Tokens and abilities"; docs/recipes.md, "Forms on Inertia React".
 *
 * The Card kit has no icon slot, so the icon tile sits in this wrapper.
 */
import type { Tone } from '../diagrams/tones'

interface Caller {
  icon: string
  eyebrow: string
  title: string
  text: string
  needs: string
  tone?: Tone
}

const callers: Caller[] = [
  {
    icon: 'form',
    eyebrow: 'Web route',
    title: 'POST /actions/create-post',
    text: 'JSON gets the output; a form gets a redirect.',
    needs: 'Actions::routes()',
  },
  {
    icon: 'ts',
    eyebrow: 'TypeScript',
    title: 'createPost()',
    text: 'The route, typed from schema() and outputSchema().',
    needs: 'actions:typescript',
  },
  {
    icon: 'terminal',
    eyebrow: 'Artisan',
    title: 'actions:run create-post',
    text: 'Runs as the user --as names.',
    needs: 'no #[Expose] needed',
  },
  {
    icon: 'queue',
    eyebrow: 'The queue',
    title: 'CreatePost::dispatch()',
    text: 'The worker runs the whole pipeline, as the caller.',
    needs: 'no #[Expose] needed',
  },
  {
    icon: 'agent',
    eyebrow: 'laravel/ai agent',
    title: 'create-post',
    text: 'The model reads $description and the schema.',
    needs: 'composer require laravel/ai',
    tone: 'agent',
  },
  {
    icon: 'mcp',
    eyebrow: 'MCP clients',
    title: 'create-post',
    text: 'Listed for a token that names actions:write.',
    needs: 'a token guard',
    tone: 'mcp',
  },
]
</script>

<template>
  <div class="hiw-callers-c">
    <ul class="hiw-callers" aria-label="What each caller gets">
      <li v-for="caller in callers" :key="caller.eyebrow" class="hiw-callers__item" :class="`aa-tone-${caller.tone ?? 'neutral'}`">
        <span class="hiw-callers__tile" aria-hidden="true"><Icon :name="caller.icon" :size="18" /></span>
        <Card :eyebrow="caller.eyebrow" :title="caller.title" mono :tone="caller.tone ?? 'neutral'">
          <span class="hiw-callers__text">{{ caller.text }}</span>
          <span class="hiw-callers__needs"><Pill :dot="false">{{ caller.needs }}</Pill></span>
        </Card>
      </li>
    </ul>
  </div>
</template>

<style>
.hiw-callers-c {
  container-type: inline-size;
}

.hiw-callers,
.vp-doc .hiw-callers {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.vp-doc .hiw-callers > li + li {
  margin-top: 0;
}

.hiw-callers__item {
  position: relative;
  display: flex;
  min-width: 0;
}

.hiw-callers__item > .aa-card {
  flex: 1;
  display: flex;
  flex-direction: column;
  padding-right: 54px;
}

.hiw-callers__item > .aa-card .aa-card__body {
  flex: 1;
  display: flex;
  flex-direction: column;
}

.hiw-callers__item .aa-card__title.is-mono {
  overflow-wrap: anywhere;
}

.hiw-callers__tile {
  position: absolute;
  top: 12px;
  right: 12px;
  z-index: 1;
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border: 1px solid var(--aa-line);
  border-radius: var(--aa-radius-sm);
  background: var(--aa-sunken);
  color: var(--aa-ink-2);
}

.hiw-callers__item:not(.aa-tone-neutral) .hiw-callers__tile {
  border-color: var(--tone-line);
  background: var(--tone-fill);
  color: var(--tone);
}

.hiw-callers__text {
  display: block;
  flex: 1;
}

.hiw-callers__needs {
  display: block;
  margin-top: 12px;
}

@container (max-width: 560px) {
  .hiw-callers,
  .vp-doc .hiw-callers {
    grid-template-columns: minmax(0, 1fr);
    gap: 10px;
  }
}
</style>
