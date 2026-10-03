<script setup lang="ts">
/**
 * TablesSplit: one table, two readers. The rows a Read action returns pass
 * through its declared columns, then split: the person gets every row as a
 * table and a chart, the model a short copy and an instruction.
 *
 * It composes a Flow for the trunk with a fork of its own, since Flow runs in
 * one line only. The fork's two branches sit side by side, and below 560px
 * they hang off one wire on the left, as a tree.
 *
 * Sources: the allowlist and the 500-row cap, docs/data.md#show-a-table and
 * #what-each-surface-gets (src/Views/Table.php output()); the person's side,
 * docs/data.md#on-the-page (js/src/views.ts ActionTable) and #the-chart; the
 * model's copy, docs/data.md#what-each-surface-gets (src/Pipeline/
 * ModelSentences.php table(), lang/en/model.php table_shown and table_hint).
 *
 *   <Figure caption="…"><TablesSplit /></Figure>
 */
</script>

<template>
  <div class="aa-tables-split">
    <Flow label="Before the split">
      <Step title="handle()" code note="returns rows: a query, an array, a generator" />
      <Step title="columns()" code note="each row keeps only the declared keys" />
    </Flow>

    <div class="aa-tables-split__fork" aria-hidden="true"><i /><i /><i /></div>

    <ul class="aa-tables-split__branches" aria-label="Then the rows split">
      <li>
        <Card eyebrow="to the person" title="Every row, as a table" tone="pass">
          <ul class="aa-tables-split__list">
            <li>a <code>data-view</code> part after the call's row, up to 500 rows</li>
            <li><code>&lt;ActionTable&gt;</code> draws it, with its time and Refresh</li>
            <li>your chart: <code>bar</code>, <code>line</code>, <code>metric</code> or <code>none</code></li>
          </ul>
        </Card>
      </li>
      <li>
        <Card eyebrow="to the model" title="A short copy" tone="agent">
          <ul class="aa-tables-split__list">
            <li><q>The person now sees this as a table of 3 rows.</q></li>
            <li>the first 20 rows, text cut to 80 characters</li>
            <li>the columns' keys, labels and descriptions</li>
            <li><q>Say in a sentence or two what stands out; do not repeat the rows.</q></li>
          </ul>
        </Card>
      </li>
    </ul>
  </div>
</template>

<style>
.aa-tables-split {
  container-type: inline-size;
}

.aa-tables-split .aa-flow-c {
  max-width: 340px;
  margin: 0 auto;
}

/* The fork: a wire down from the trunk, across, and down into each branch. */
.aa-tables-split__fork {
  position: relative;
  height: 32px;
}

.aa-tables-split__fork i {
  position: absolute;
  display: block;
}

.aa-tables-split__fork i:nth-child(1) {
  left: 50%;
  top: 0;
  height: 50%;
  border-left: 1.5px dotted var(--aa-wire);
}

.aa-tables-split__fork i:nth-child(2) {
  left: 25%;
  right: 25%;
  top: 50%;
  border-top: 1.5px dotted var(--aa-wire);
}

.aa-tables-split__fork i:nth-child(3) {
  left: 25%;
  right: 25%;
  top: 50%;
  bottom: 0;
  border-left: 1.5px dotted var(--aa-wire);
  border-right: 1.5px dotted var(--aa-wire);
}

.aa-tables-split__branches {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.vp-doc .aa-tables-split__branches {
  margin: 0;
}

.vp-doc .aa-tables-split__branches,
.vp-doc .aa-tables-split__list {
  padding: 0;
  list-style: none;
}

.vp-doc .aa-tables-split__branches > li {
  margin: 0;
}

.aa-tables-split__branches > li {
  display: flex;
  min-width: 0;
}

.aa-tables-split__branches .aa-card {
  flex: 1;
}

.aa-tables-split__list,
.vp-doc .aa-tables-split__list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 8px 0 0;
  padding: 0;
  list-style: none;
}

.vp-doc .aa-tables-split__list li {
  margin: 0;
}

.aa-tables-split__list li {
  position: relative;
  padding-left: 14px;
  color: var(--aa-ink-2);
  font-size: var(--aa-fs-sm);
  line-height: 1.45;
}

.aa-tables-split__list li::before {
  content: '';
  position: absolute;
  top: 0.62em;
  left: 2px;
  width: 5px;
  height: 1.5px;
  background: var(--aa-wire);
}

.aa-tables-split__list q {
  color: var(--aa-ink);
  font-style: italic;
}

.vp-doc .aa-tables-split__list code {
  font-size: var(--aa-fs-xs);
}

/* Narrow: the branches hang off one wire on the left, as a tree. */
@container (max-width: 560px) {
  .aa-tables-split__fork {
    height: 20px;
  }

  .aa-tables-split__fork i:nth-child(1) {
    left: 9px;
    height: 100%;
  }

  .aa-tables-split__fork i:nth-child(2),
  .aa-tables-split__fork i:nth-child(3) {
    display: none;
  }

  .aa-tables-split__branches,
  .vp-doc .aa-tables-split__branches {
    grid-template-columns: 1fr;
    gap: 14px;
    margin-left: 9px;
    padding-left: 16px;
  }

  .aa-tables-split__branches > li {
    position: relative;
  }

  /* A tick into each branch, and the wire down to it from the one before. */
  .aa-tables-split__branches > li::before,
  .aa-tables-split__branches > li::after {
    content: '';
    position: absolute;
    left: -16px;
  }

  .aa-tables-split__branches > li::before {
    top: 22px;
    width: 14px;
    border-top: 1.5px dotted var(--aa-wire);
  }

  .aa-tables-split__branches > li::after {
    top: -14px;
    height: calc(100% + 14px);
    border-left: 1.5px dotted var(--aa-wire);
  }

  .aa-tables-split__branches > li:first-child::after {
    top: 0;
    height: 100%;
  }

  .aa-tables-split__branches > li:last-child::after {
    height: 36px;
  }
}
</style>
