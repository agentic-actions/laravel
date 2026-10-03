<script setup lang="ts">
/**
 * Matrix: rows by columns, each cell a mark: yes, no, after a confirmation,
 * asks the person, or not applicable, with its words always shown beside the
 * symbol. It is a real table with row and column headers; when its container
 * is narrower than 560px each row becomes a small block with the column names
 * beside the marks.
 *
 * Use it for "which caller reaches which effect" and other yes/no grids; put
 * long explanations in the text, not in the cells.
 *
 * Props: columns (the column headings), rows, a list of { label, code?, cells }
 * where each cell is a mark ('yes', 'no', 'confirm', 'ask', 'na'), any other
 * string (shown as is), or { mark?, text?, tone? } to change the words; corner
 * (the top-left heading), label (the table's accessible name, when the Figure's
 * caption does not already say it).
 *
 *   <Matrix corner="Effect" :columns="['Agents', 'MCP']" :rows="[
 *     { label: 'Read', cells: ['yes', 'yes'] },
 *     { label: 'Destructive', cells: ['confirm', 'no'] },
 *   ]" />
 */
import { toneClass, type Tone } from './tones'

type Mark = 'yes' | 'no' | 'confirm' | 'ask' | 'na'
type Cell = Mark | string | { mark?: Mark; text?: string; tone?: Tone }

interface Row {
  label: string
  code?: boolean
  cells: Cell[]
}

defineProps<{ columns: string[]; rows: Row[]; corner?: string; label?: string }>()

const marks: Record<Mark, { symbol: string; text: string; tone: Tone }> = {
  yes: { symbol: '✓', text: 'yes', tone: 'pass' },
  no: { symbol: '✕', text: 'no', tone: 'refuse' },
  confirm: { symbol: '◷', text: 'after a confirmation', tone: 'wait' },
  ask: { symbol: '?', text: 'asks the person', tone: 'wait' },
  na: { symbol: '–', text: 'not applicable', tone: 'neutral' },
}

function read(cell: Cell): { symbol?: string; text: string; tone: Tone } {
  if (typeof cell === 'string') {
    return cell in marks ? marks[cell as Mark] : { text: cell, tone: 'neutral' }
  }

  const mark = cell.mark ? marks[cell.mark] : undefined

  return { symbol: mark?.symbol, text: cell.text ?? mark?.text ?? '', tone: cell.tone ?? mark?.tone ?? 'neutral' }
}
</script>

<template>
  <div class="aa-matrix-c">
    <table class="aa-matrix" :aria-label="label">
      <thead>
        <tr>
          <th scope="col" class="aa-matrix__corner">{{ corner }}</th>
          <th v-for="column in columns" :key="column" scope="col">{{ column }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.label">
          <th scope="row" :class="{ 'is-code': row.code }">{{ row.label }}</th>
          <td v-for="(cell, i) in row.cells" :key="i" :data-label="columns[i]">
            <span class="aa-matrix__mark" :class="toneClass(read(cell).tone)">
              <span v-if="read(cell).symbol" class="aa-matrix__symbol" aria-hidden="true">{{ read(cell).symbol }}</span>
              <span>{{ read(cell).text }}</span>
            </span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style>
.aa-matrix-c {
  container-type: inline-size;
}

.aa-matrix,
.vp-doc table.aa-matrix {
  display: table;
  width: 100%;
  margin: 0;
  overflow: hidden;
  border: 1px solid var(--aa-line-strong);
  border-collapse: separate;
  border-spacing: 0;
  border-radius: var(--aa-radius);
  background: var(--aa-surface);
  font-size: var(--aa-fs-sm);
  line-height: 1.4;
}

.aa-matrix tr,
.vp-doc table.aa-matrix tr {
  border: 0;
  background: none;
}

.aa-matrix th,
.aa-matrix td,
.vp-doc table.aa-matrix th,
.vp-doc table.aa-matrix td {
  padding: 10px 14px;
  border: 0;
  border-top: 1px solid var(--aa-line);
  background: none;
  color: var(--aa-ink);
  text-align: left;
  vertical-align: middle;
}

.aa-matrix thead th,
.vp-doc table.aa-matrix thead th {
  border-top: 0;
  background: var(--aa-sunken);
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  font-weight: 400;
  letter-spacing: var(--aa-tracking-label);
  text-transform: uppercase;
}

.aa-matrix tbody th,
.vp-doc table.aa-matrix tbody th {
  font-weight: 500;
}

.aa-matrix tbody th.is-code {
  font-family: var(--aa-font-mono);
  font-weight: 400;
}

.aa-matrix__mark {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  color: var(--aa-ink-2);
}

.aa-matrix__symbol {
  display: inline-grid;
  place-items: center;
  flex: none;
  width: 20px;
  height: 20px;
  border: 1px solid var(--tone-line);
  border-radius: 50%;
  background: var(--tone-fill);
  color: var(--tone);
  font-size: var(--aa-fs-xs);
  line-height: 1;
}

@container (max-width: 560px) {
  .aa-matrix,
  .aa-matrix tbody,
  .aa-matrix tr,
  .aa-matrix th,
  .aa-matrix td,
  .vp-doc table.aa-matrix,
  .vp-doc table.aa-matrix tr,
  .vp-doc table.aa-matrix th,
  .vp-doc table.aa-matrix td {
    display: block;
  }

  .aa-matrix thead {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
  }

  .aa-matrix tbody tr + tr {
    border-top: 1px solid var(--aa-line-strong);
  }

  .aa-matrix tbody th,
  .vp-doc table.aa-matrix tbody th {
    border-top: 0;
    padding-bottom: 4px;
  }

  .aa-matrix td,
  .vp-doc table.aa-matrix td {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding-top: 6px;
    padding-bottom: 6px;
    border-top: 0;
  }

  .aa-matrix td::before {
    content: attr(data-label);
    color: var(--aa-muted);
  }

  .aa-matrix tbody tr td:last-child {
    padding-bottom: 12px;
  }
}
</style>
