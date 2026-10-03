<script setup lang="ts">
/**
 * AgentsTools: from toolsets to an agent's tool list to one call. On top, the
 * actions sorted into toolsets beside the tools one agent receives this turn;
 * under them, one call of a tool and the sentence the model reads back.
 *
 * The toolsets are the workbench's (workbench/actions.exposure.json), the
 * tool list is built per turn from steps 1 to 5 of the pipeline
 * (docs/concepts.md#what-an-agents-tool-list-shows), and the sentences are
 * lang/en/model.php's.
 *
 *   <Figure caption="…"><AgentsTools /></Figure>
 */
</script>

<template>
  <div class="aa-agents-tools">
    <Columns arrows align="start">
      <Card eyebrow="toolsets" title="#[Expose]" mono>
        <div class="aa-agents-tools__rows">
          <ListRow mono label="create-post" status="default" />
          <ListRow mono label="post-stats" status="default" />
          <ListRow mono label="delete-post" status="team" />
        </div>
      </Card>
      <Card eyebrow="tools this turn" title="BlogAssistant" mono tone="agent">
        <p class="aa-agents-tools__sub"><code>#[UseToolset]</code> receives <code>default</code>, built for the signed-in author</p>
        <div class="aa-agents-tools__rows">
          <ListRow mono icon="check" tone="pass" label="create-post" />
          <ListRow mono icon="check" tone="pass" label="post-stats" />
        </div>
        <p class="aa-agents-tools__out">Left out: an action whose checks before input say no.</p>
      </Card>
    </Columns>
    <div class="aa-agents-tools__call">
      <Columns :cols="3" arrows>
        <Card eyebrow="the model calls" title="create-post" mono tone="agent" />
        <Card eyebrow="then runs" title="the pipeline">every check, as the author</Card>
        <Card eyebrow="the model reads" title="“Done.”" tone="pass">or “Not done. Rejected: title (max).”</Card>
      </Columns>
    </div>
  </div>
</template>

<style>
.aa-agents-tools {
  display: flex;
  flex-direction: column;
  gap: 28px;
}

.aa-agents-tools__rows {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin-top: 8px;
}

.vp-doc .aa-agents-tools p.aa-agents-tools__sub {
  margin: 4px 0 0;
  color: var(--aa-muted);
  font-size: var(--aa-fs-xs);
  line-height: 1.5;
}

.vp-doc .aa-agents-tools p.aa-agents-tools__out {
  margin: 10px 0 0;
  padding: 6px 9px;
  border: 1px dashed var(--aa-line-strong);
  border-radius: var(--aa-radius-sm);
  color: var(--aa-muted);
  font-size: var(--aa-fs-xs);
  line-height: 1.45;
}

.vp-doc .aa-agents-tools__sub code {
  padding: 0;
  background: none;
  color: var(--aa-ink-2);
  font-size: var(--aa-fs-xs);
}

.aa-agents-tools__call {
  padding-top: 24px;
  border-top: 1px dashed var(--aa-line-strong);
}
</style>
