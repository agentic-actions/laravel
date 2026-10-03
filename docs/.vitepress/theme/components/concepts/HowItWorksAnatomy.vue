<script setup lang="ts">
/**
 * HowItWorksAnatomy: the README's CreatePost class read line by line, each
 * member with a dotted leader to what it does (docs/concepts.md, "The action").
 * Properties declare facts and methods behaviour, so the two groups carry
 * their own eyebrow. Below 600px the note moves under its line.
 *
 * Built on CodeCard for the file tab; the lines are a list, so a screen reader
 * reads each member and its note in order.
 */
interface Line {
  code: string
  note?: string
  indent?: boolean
  group?: string
}

const lines: Line[] = [
  { code: '#[Expose]', note: 'opens the route, agents and MCP' },
  { code: 'final class CreatePost extends Action' },
  { code: '$description', note: 'what a model reads before it calls', indent: true, group: 'facts' },
  { code: '$effect = Effect::Write', note: 'Read, Write, Destructive or External', indent: true },
  { code: "$touches = ['posts']", note: 'what a success makes stale', indent: true },
  { code: 'schema()', note: 'the input: rules, tool schema, TypeScript types', indent: true, group: 'behaviour' },
  { code: 'outputSchema()', note: 'the only keys that leave the server', indent: true },
  { code: 'authorize()', note: 'who may run it; without it, nobody', indent: true },
  { code: 'handle()', note: 'does the work', indent: true },
]
</script>

<template>
  <div class="hiw-anatomy-c">
    <CodeCard file="app/Actions/CreatePost.php">
      <ul class="hiw-anatomy" aria-label="The parts of CreatePost">
        <template v-for="line in lines" :key="line.code">
          <li v-if="line.group" class="hiw-anatomy__group" aria-hidden="true">{{ line.group }}</li>
          <li class="hiw-anatomy__line" :class="{ 'is-indent': line.indent, 'has-note': line.note }">
            <code class="hiw-anatomy__code">{{ line.code }}</code>
            <span v-if="line.note" class="hiw-anatomy__note">{{ line.note }}</span>
          </li>
        </template>
      </ul>
    </CodeCard>
  </div>
</template>

<style>
.hiw-anatomy-c {
  container-type: inline-size;
}

.hiw-anatomy,
.vp-doc .hiw-anatomy {
  margin: 0;
  padding: 14px 18px 16px;
  list-style: none;
}

.vp-doc .hiw-anatomy > li + li {
  margin-top: 0;
}

.hiw-anatomy__group {
  margin: 10px 0 2px 2ch;
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  letter-spacing: var(--aa-tracking-label);
  text-transform: uppercase;
}

.hiw-anatomy__line {
  display: grid;
  grid-template-columns: 31ch minmax(0, 1fr);
  font-family: var(--aa-font-mono); /* so ch is a monospace character */
  font-size: var(--aa-fs-sm);
  align-items: center;
  column-gap: 0;
  min-height: 30px;
}

.hiw-anatomy__line:not(.has-note) {
  grid-template-columns: minmax(0, 1fr);
}

.vp-doc .hiw-anatomy__code,
.hiw-anatomy__code {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
  padding: 0;
  border-radius: 0;
  background: none;
  color: var(--aa-ink);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-sm);
  white-space: nowrap;
}

.hiw-anatomy__line.is-indent .hiw-anatomy__code {
  padding-left: 2ch;
}

/* The dotted leader from the line to its note, ending in a small dot. */
.hiw-anatomy__line.has-note .hiw-anatomy__code::after {
  content: '';
  flex: 1 1 16px;
  min-width: 16px;
  height: 6px;
  margin-right: 10px;
  background:
    radial-gradient(circle, var(--aa-wire) 2.5px, transparent 3px) right center / 6px 6px no-repeat,
    repeating-linear-gradient(90deg, var(--aa-wire) 0 1.5px, transparent 1.5px 4px) left center / calc(100% - 8px) 1.5px no-repeat;
}

.hiw-anatomy__note {
  color: var(--aa-ink-2);
  font-family: var(--aa-font-sans);
  font-size: var(--aa-fs-sm);
  line-height: 1.45;
}

/* Narrow: the note sits under its line, behind a dotted rule. */
@container (max-width: 600px) {
  .hiw-anatomy,
  .vp-doc .hiw-anatomy {
    padding: 12px 14px 14px;
  }

  .hiw-anatomy__line,
  .hiw-anatomy__line.has-note {
    grid-template-columns: minmax(0, 1fr);
    padding: 4px 0;
  }

  .hiw-anatomy__line.has-note .hiw-anatomy__code::after {
    display: none;
  }

  .hiw-anatomy__code {
    white-space: normal;
    overflow-wrap: anywhere;
  }

  .hiw-anatomy__note {
    margin: 2px 0 0 1ch;
    padding-left: 10px;
    border-left: 1.5px dotted var(--aa-wire);
  }

  .hiw-anatomy__line.is-indent .hiw-anatomy__note {
    margin-left: 3ch;
  }

  .hiw-anatomy__group {
    margin-top: 8px;
  }
}
</style>
