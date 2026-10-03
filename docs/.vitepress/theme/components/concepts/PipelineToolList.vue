<script setup lang="ts">
/**
 * PipelineToolList: how an agent's tool list is built before each turn.
 * Steps 1 to 5 run for every action in the agent's toolsets; the list keeps
 * the actions none of them refused. The action names are illustrations.
 *
 * Source: docs/concepts.md "What an agent's tool list shows".
 *
 *   <Figure caption="…"><PipelineToolList /></Figure>
 */
import Columns from '../diagrams/Columns.vue'
import Flow from '../diagrams/Flow.vue'
import Step from '../diagrams/Step.vue'
import Window from '../diagrams/Window.vue'
import ListRow from '../diagrams/ListRow.vue'
</script>

<template>
  <Columns arrows>
    <div class="pl-tools__steps">
      <p class="pl-tools__eyebrow">Before each turn, per action</p>
      <Flow vertical numbered compact label="Steps 1 to 5">
        <Step title="the door" />
        <Step title="the token" />
        <Step title="shouldRegister()" code />
        <Step title="tenant membership" tone="tenant" />
        <Step title="authorize() without input" code />
      </Flow>
    </div>
    <Window title="The agent's tools this turn">
      <ListRow mono tone="pass" label="create-post" status="offered" />
      <ListRow mono tone="pass" label="list-posts" status="offered" />
      <ListRow mono tone="pass" label="update-post" detail="authorize() takes input: it runs at the call" status="offered" />
      <ListRow mono tone="refuse" label="publish-post" detail="authorize() said no" status="left out" />
      <ListRow mono tone="refuse" label="import-posts" detail="shouldRegister() said no" status="left out" />
    </Window>
  </Columns>
</template>

<style>
.pl-tools__eyebrow {
  margin: 0 0 12px;
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.vp-doc .pl-tools__eyebrow {
  margin: 0 0 12px;
}

.pl-tools__steps .aa-flow {
  --aa-flow-gap: 14px;
}
</style>
