<script setup lang="ts">
/**
 * TablesAnswer: a copilot answer that shows a table. The person asks, the
 * Read row is done, the table follows its row with its time and Refresh, the
 * page draws a bar chart from the same rows, and the agent says in a sentence
 * what stands out. Three numbered notes beside the window say who drew what.
 *
 * The rows are PostsPerAuthor's, from the example on concepts/tables: the
 * columns are author (text), posts (integer) and published (percent), so the
 * chart the server names is a bar of posts by author, and the percentage is
 * left out of it (docs/data.md#the-chart, src/Views/Table.php chart()).
 * The row label is lang/en/activity.php read_done; Refresh is
 * lang/en/views.php refresh.
 *
 *   <Figure caption="…"><TablesAnswer /></Figure>
 */
const rows = [
  { author: 'Sam', posts: 12, published: '75%' },
  { author: 'Alex', posts: 8, published: '50%' },
  { author: 'Robin', posts: 5, published: '100%' },
]
const most = Math.max(...rows.map((row) => row.posts))
</script>

<template>
  <div class="aa-tables-answer">
    <Window title="Copilot" width="400px" class="aa-tables-answer__window">
      <Bubble>Who posts the most?</Bubble>
      <ListRow boxed icon="check" tone="pass" label="Looked it up" status="done" />

      <div class="aa-tables-answer__view">
        <span class="aa-tables-answer__marker" aria-hidden="true">1</span>
        <table class="aa-tables-answer__table">
          <thead>
            <tr>
              <th scope="col">Author</th>
              <th scope="col">Posts</th>
              <th scope="col">Published</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.author">
              <td>{{ row.author }}</td>
              <td>{{ row.posts }}</td>
              <td>{{ row.published }}</td>
            </tr>
          </tbody>
        </table>
        <div class="aa-tables-answer__foot">
          <time datetime="2026-10-03T10:42:00Z">3 Oct 2026, 10:42</time>
          <MockButton>Refresh</MockButton>
        </div>
      </div>

      <div class="aa-tables-answer__chart">
        <span class="aa-tables-answer__marker" aria-hidden="true">2</span>
        <span class="aa-tables-answer__chart-label">Posts by author, as a bar chart</span>
        <div class="aa-tables-answer__bars" aria-hidden="true">
          <span v-for="row in rows" :key="row.author" class="aa-tables-answer__bar">
            <b>{{ row.posts }}</b>
            <i :style="{ height: `${(row.posts / most) * 100}%` }" />
            <span>{{ row.author }}</span>
          </span>
        </div>
      </div>

      <div class="aa-tables-answer__reply">
        <span class="aa-tables-answer__marker" aria-hidden="true">3</span>
        <Bubble from="agent">Sam posts the most. Robin has published every post.</Bubble>
      </div>
    </Window>

    <ol class="aa-tables-answer__notes" aria-label="Who drew what">
      <li>
        <span class="aa-tables-answer__eyebrow">ActionTable</span>
        <span>The rows your query returned, under the columns you declared.</span>
      </li>
      <li>
        <span class="aa-tables-answer__eyebrow">your chart</span>
        <span>The server names the kind, <code>bar</code>. Your page draws it.</span>
      </li>
      <li>
        <span class="aa-tables-answer__eyebrow aa-tone-agent">the model</span>
        <span>Told to say in a sentence or two what stands out, not to repeat the rows.</span>
      </li>
    </ol>
  </div>
</template>

<style>
.aa-tables-answer {
  display: grid;
  grid-template-columns: minmax(0, 400px) minmax(0, 220px);
  justify-content: center;
  align-items: center;
  gap: 28px;
}

.aa-tables-answer__window {
  width: 100%;
}

.aa-tables-answer__view,
.aa-tables-answer__chart,
.aa-tables-answer__reply {
  position: relative;
}

.aa-tables-answer__view,
.aa-tables-answer__chart {
  padding: 10px 12px;
  border: 1px solid var(--aa-line);
  border-radius: var(--aa-radius-sm);
  background: var(--aa-surface);
}

/* A numbered marker on the window's edge, matching a note beside it. */
.aa-tables-answer__marker {
  position: absolute;
  top: 8px;
  right: -9px;
  display: grid;
  place-items: center;
  width: 18px;
  height: 18px;
  border: 1px solid var(--aa-ink);
  border-radius: 50%;
  background: var(--aa-ink);
  color: var(--aa-surface);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  line-height: 1;
  z-index: 1;
}

.aa-tables-answer__reply .aa-tables-answer__marker {
  top: 50%;
  margin-top: -9px;
}

.aa-tables-answer__table,
.vp-doc table.aa-tables-answer__table {
  display: table;
  width: 100%;
  margin: 0;
  border: 0;
  border-collapse: collapse;
  font-size: var(--aa-fs-xs);
}

.aa-tables-answer__table tr,
.vp-doc table.aa-tables-answer__table tr {
  border: 0;
  background: none;
}

.aa-tables-answer__table th,
.aa-tables-answer__table td,
.vp-doc table.aa-tables-answer__table th,
.vp-doc table.aa-tables-answer__table td {
  padding: 4px 6px;
  border: 0;
  border-bottom: 1px solid var(--aa-line);
  background: none;
  color: var(--aa-ink);
  font-size: var(--aa-fs-xs);
  font-weight: 400;
  line-height: 1.5;
  text-align: end;
  font-variant-numeric: tabular-nums;
}

.aa-tables-answer__table th:first-child,
.aa-tables-answer__table td:first-child,
.vp-doc table.aa-tables-answer__table th:first-child,
.vp-doc table.aa-tables-answer__table td:first-child {
  padding-left: 0;
  text-align: start;
}

.aa-tables-answer__table thead th,
.vp-doc table.aa-tables-answer__table thead th {
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
}

.aa-tables-answer__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 8px;
  color: var(--aa-muted);
  font-size: var(--aa-fs-xs);
}

.aa-tables-answer__chart-label {
  display: block;
  margin-bottom: 6px;
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
}

.aa-tables-answer__bars {
  display: flex;
  align-items: flex-end;
  justify-content: space-around;
  gap: 16px;
  height: 92px;
  padding: 0 4px;
}

.aa-tables-answer__bar {
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
  text-align: center;
}

.aa-tables-answer__bar b {
  font-weight: 400;
}

.aa-tables-answer__bar i {
  display: block;
  width: 36px;
  max-height: calc(100% - 38px);
  min-height: 4px;
  border-radius: 3px 3px 0 0;
  background: var(--aa-wire);
}

.aa-tables-answer__bar span {
  align-self: stretch;
  padding-top: 3px;
  border-top: 1px solid var(--aa-line-strong);
  color: var(--aa-muted);
}

.aa-tables-answer__notes {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin: 0;
  padding: 0;
  list-style: none;
  counter-reset: aa-tables-note;
}

.vp-doc .aa-tables-answer__notes {
  margin: 0;
  padding: 0;
  list-style: none;
}

.vp-doc .aa-tables-answer__notes li {
  margin: 0;
}

.aa-tables-answer__notes li {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding-left: 28px;
  color: var(--aa-ink-2);
  font-size: var(--aa-fs-sm);
  line-height: 1.45;
  counter-increment: aa-tables-note;
}

.aa-tables-answer__notes li::before {
  content: counter(aa-tables-note);
  position: absolute;
  top: 1px;
  left: 0;
  display: grid;
  place-items: center;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: var(--aa-ink);
  color: var(--aa-surface);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  line-height: 1;
}

.aa-tables-answer__eyebrow {
  color: var(--aa-ink);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
}

.aa-tables-answer__eyebrow.aa-tone-agent {
  color: var(--tone);
}

.vp-doc .aa-tables-answer__notes code {
  font-size: var(--aa-fs-xs);
}

@media (forced-colors: active) {
  .aa-tables-answer__bar i {
    forced-color-adjust: none;
    background: CanvasText;
  }
}

@container (max-width: 680px) {
  .aa-tables-answer {
    grid-template-columns: minmax(0, 400px);
  }
}
</style>
