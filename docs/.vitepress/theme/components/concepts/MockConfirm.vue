<script setup lang="ts">
/**
 * MockConfirm: a confirmation card in the chat. An agent called a Destructive
 * action, and nothing runs until the person answers.
 *
 * The sentence and rows are DeletePost's approvalReason() and
 * approvalSummary() from docs/copilot.md#confirmations; the pill and the
 * buttons are the package's own words (lang/en/approval.php).
 */
</script>

<template>
  <Window title="Copilot" width="420px">
    <Bubble>Delete my old meetup draft.</Bubble>
    <Card tone="wait" status="Waiting for your confirmation" status-tone="wait" class="aa-mock-confirm">
      <p class="aa-mock-confirm__reason">Delete this post? This cannot be undone.</p>
      <dl class="aa-mock-confirm__rows">
        <dt>Post</dt>
        <dd>Spring meetup</dd>
        <dt>Status</dt>
        <dd>draft</dd>
      </dl>
      <div class="aa-mock-confirm__actions">
        <MockButton>Decline</MockButton>
        <MockButton primary>Confirm</MockButton>
      </div>
    </Card>
  </Window>
</template>

<style>
.aa-mock-confirm .aa-card__head {
  justify-content: flex-start;
}

.aa-mock-confirm__reason,
.vp-doc .aa-mock-confirm__reason {
  margin: 4px 0 8px;
  color: var(--aa-ink);
  font-weight: 500;
}

.aa-mock-confirm__rows,
.vp-doc .aa-mock-confirm__rows {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 2px 14px;
  margin: 0 0 12px;
}

.aa-mock-confirm__rows dt {
  color: var(--aa-muted);
}

.aa-mock-confirm__rows dd,
.vp-doc .aa-mock-confirm__rows dd {
  margin: 0;
  color: var(--aa-ink);
}

.aa-mock-confirm__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
</style>
