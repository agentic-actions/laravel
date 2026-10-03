<script setup lang="ts">
/**
 * MockTable: a Read action's rows shown as a table after its copilot row,
 * with the bar chart the page draws from the same rows. The package picks the
 * chart's kind and draws none itself (docs/data.md#the-chart). As on the
 * Tables page, each value sits above its bar and each name under the axis.
 */
const rows = [
  { author: 'Sam', posts: 12, words: 9400 },
  { author: 'Alex', posts: 8, words: 6100 },
  { author: 'Robin', posts: 5, words: 2800 },
]
const most = Math.max(...rows.map((row) => row.words))
</script>

<template>
  <Window title="Copilot" width="420px" class="aa-mock-table">
    <ListRow boxed icon="check" tone="pass" label="Looked it up" status="done" />
    <table class="aa-mock-table__table">
      <thead>
        <tr><th scope="col">Author</th><th scope="col">Posts</th><th scope="col">Words</th></tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.author">
          <th scope="row">{{ row.author }}</th>
          <td>{{ row.posts }}</td>
          <td>{{ row.words.toLocaleString('en-US') }}</td>
        </tr>
      </tbody>
    </table>
    <div class="aa-mock-table__chart" aria-hidden="true">
      <span v-for="row in rows" :key="row.author" class="aa-mock-table__bar">
        <b>{{ row.words.toLocaleString('en-US') }}</b>
        <i :style="{ height: `${(row.words / most) * 100}%` }" />
        <span>{{ row.author }}</span>
      </span>
    </div>
    <Bubble from="agent">Sam wrote the most words this month.</Bubble>
  </Window>
</template>

<style>
.aa-mock-table__table,
.vp-doc table.aa-mock-table__table {
  display: table;
  width: 100%;
  margin: 0;
  border: 1px solid var(--aa-line);
  border-collapse: separate;
  border-spacing: 0;
  border-radius: var(--aa-radius-sm);
  font-size: var(--aa-fs-xs);
}

.aa-mock-table__table tr,
.vp-doc table.aa-mock-table__table tr {
  border: 0;
  background: none;
}

.aa-mock-table__table th,
.aa-mock-table__table td,
.vp-doc table.aa-mock-table__table th,
.vp-doc table.aa-mock-table__table td {
  padding: 5px 10px;
  border: 0;
  border-top: 1px solid var(--aa-line);
  background: none;
  color: var(--aa-ink);
  font-weight: 400;
  font-size: var(--aa-fs-xs);
  line-height: 1.5;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.aa-mock-table__table th:first-child,
.vp-doc table.aa-mock-table__table th:first-child {
  text-align: left;
}

.aa-mock-table__table thead th,
.vp-doc table.aa-mock-table__table thead th {
  border-top: 0;
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
}

.aa-mock-table__chart {
  display: flex;
  align-items: flex-end;
  justify-content: space-around;
  gap: 14px;
  height: 92px;
  padding: 0 4px;
}

.aa-mock-table__bar {
  display: flex;
  flex: 0 1 64px;
  flex-direction: column;
  justify-content: flex-end;
  align-items: center;
  gap: 3px;
  height: 100%;
  color: var(--aa-ink);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  line-height: 1.2;
}

.aa-mock-table__bar b {
  font-weight: 400;
}

.aa-mock-table__bar i {
  display: block;
  width: 36px;
  max-height: calc(100% - 38px);
  min-height: 4px;
  border-radius: 3px 3px 0 0;
  background: var(--aa-wire);
}

.aa-mock-table__bar span {
  align-self: stretch;
  padding-top: 3px;
  border-top: 1px solid var(--aa-line-strong);
  color: var(--aa-muted);
  text-align: center;
}

@media (forced-colors: active) {
  .aa-mock-table__bar i {
    forced-color-adjust: none;
    background: CanvasText;
  }
}
</style>
