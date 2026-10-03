<script setup lang="ts">
/**
 * ConfirmationsCard: the confirmation card in the chat, and the row each answer
 * leaves behind.
 *
 * The card's label and buttons are the package's own words
 * (lang/en/approval.php); the sentence and rows are DeletePost's
 * approvalReason() and approvalSummary() in docs/copilot.md#confirmations. The
 * rows after an answer are the defaults for a Destructive action and a decline
 * (lang/en/activity.php) with their statuses (docs/copilot.md#statuses).
 *
 * The shared MockConfirm puts the label beside the title, and Columns splits
 * evenly; here the label has its own line and the chat takes the wider
 * column, so the label never wraps. Below 560px the answers stack under it.
 *
 *   <Figure caption="…"><ConfirmationsCard /></Figure>
 */
</script>

<template>
  <div class="aa-confirm-card-c"><div class="aa-confirm-card-grid">
    <div class="aa-confirm-card__chat">
    <Window title="Copilot" class="aa-confirm-card">
      <Bubble>Delete my old meetup draft.</Bubble>
      <div class="aa-confirm-card__card aa-tone-wait" role="group" aria-label="Confirmation card">
        <Pill tone="wait">Waiting for your confirmation</Pill>
        <p class="aa-confirm-card__reason">Delete this post? This cannot be undone.</p>
        <dl class="aa-confirm-card__rows">
          <dt>Post</dt>
          <dd>Spring meetup</dd>
          <dt>Status</dt>
          <dd>draft</dd>
        </dl>
        <div class="aa-confirm-card__actions">
          <MockButton>Decline</MockButton>
          <MockButton primary>Confirm</MockButton>
        </div>
      </div>
    </Window>
    </div>
    <div class="aa-confirm-card__after">
      <div class="aa-confirm-card__answer">
        <span class="aa-confirm-card__label">After Confirm</span>
        <ListRow boxed icon="check" tone="pass" label="Removed" status="done" />
      </div>
      <div class="aa-confirm-card__answer">
        <span class="aa-confirm-card__label">After Decline</span>
        <ListRow boxed icon="x" label="Declined" status="declined" />
      </div>
    </div>
  </div></div>
</template>

<style>
.aa-confirm-card-c {
  container-type: inline-size;
}

.aa-confirm-card-grid {
  display: grid;
  grid-template-columns: minmax(0, 7fr) minmax(0, 5fr);
  align-items: center;
  gap: 40px;
}

.aa-confirm-card__chat {
  position: relative;
  min-width: 0;
}

.aa-confirm-card__chat::after,
.aa-confirm-card__chat::before {
  content: '';
  position: absolute;
  pointer-events: none;
}

.aa-confirm-card__chat::after {
  left: calc(100% + 8px);
  top: 50%;
  width: 24px;
  border-top: 1.5px dotted var(--aa-wire);
}

.aa-confirm-card__chat::before {
  left: calc(100% + 26px);
  top: calc(50% - 3px);
  width: 6px;
  height: 6px;
  border-top: 1.5px solid var(--aa-wire);
  border-right: 1.5px solid var(--aa-wire);
  transform: rotate(45deg);
}

@container (max-width: 560px) {
  .aa-confirm-card-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .aa-confirm-card__chat::after {
    left: 50%;
    top: calc(100% + 8px);
    width: 0;
    height: 24px;
    border-top: 0;
    border-left: 1.5px dotted var(--aa-wire);
  }

  .aa-confirm-card__chat::before {
    left: calc(50% - 3px);
    top: calc(100% + 26px);
    transform: rotate(135deg);
  }
}

.aa-confirm-card__card {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 8px;
  padding: 12px 14px;
  border: 1px solid var(--tone-line);
  border-radius: var(--aa-radius);
  background: var(--aa-surface);
}

.aa-confirm-card__reason,
.vp-doc .aa-confirm-card__reason {
  margin: 0;
  color: var(--aa-ink);
  font-weight: 500;
  line-height: 1.45;
}

.aa-confirm-card__rows,
.vp-doc .aa-confirm-card__rows {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 2px 14px;
  margin: 0;
}

.aa-confirm-card__rows dt {
  color: var(--aa-muted);
}

.aa-confirm-card__rows dd,
.vp-doc .aa-confirm-card__rows dd {
  margin: 0;
  color: var(--aa-ink);
}

.aa-confirm-card__actions {
  display: flex;
  align-self: stretch;
  justify-content: flex-end;
  gap: 8px;
}

.aa-confirm-card__after {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.aa-confirm-card__answer {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.aa-confirm-card__label {
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  letter-spacing: var(--aa-tracking-label);
  text-transform: uppercase;
}
</style>
