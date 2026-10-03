<script setup lang="ts">
/**
 * ConfirmationsAsk: a model's incomplete call to DraftPost, the form the person
 * fills in, and where their values go.
 *
 * DraftPost is docs/asking.md#opt-in: the model gives the title alone, ask()
 * confirms the title, shows the body as a textarea and the status as a choice.
 * The form's order and controls follow <ElicitationForm> (js/src/react.ts):
 * the line naming the app (lang/en/ask.php "Asked by :app", with a fresh app's
 * app.name), the sentence, one field per property with a required marker, then
 * Submit, Decline and Not now. What the model reads is Form::filled() and the
 * action's usual "Done." (docs/asking.md#what-the-model-reads).
 *
 * The fields are drawn, not real inputs, so a reader never tabs into a picture.
 *
 *   <Figure caption="…"><ConfirmationsAsk /></Figure>
 */
</script>

<template>
  <div class="aa-ask">
    <Flow numbered compact label="An incomplete call that asks the person">
      <Step title="draft-post, title only" code tone="agent" note="the model leaves fields out" />
      <Step title="The form waits" tone="wait" note="MCP's form-elicitation shape" />
      <Step title="The person submits" note="checked against your rules" branch="declined: nothing runs" />
      <Step title="handle()" code tone="pass" note="with the person's values" />
    </Flow>
    <Columns arrows>
      <Window title="Copilot" class="aa-ask__window">
        <div class="aa-ask__form" role="group" aria-label="Form">
          <span class="aa-ask__source">Asked by Laravel</span>
          <p class="aa-ask__message">A few details for your post.</p>
          <div class="aa-ask__field">
            <span class="aa-ask__name">Title <span aria-hidden="true">*</span></span>
            <span class="aa-ask__input">Launch notes</span>
          </div>
          <div class="aa-ask__field">
            <span class="aa-ask__name">Body <span aria-hidden="true">*</span></span>
            <span class="aa-ask__input is-area">What shipped this week, and what comes next.</span>
          </div>
          <div class="aa-ask__field">
            <span class="aa-ask__name">Status <span aria-hidden="true">*</span></span>
            <span class="aa-ask__input is-select">Draft<svg aria-hidden="true" viewBox="0 0 12 12" width="12" height="12"><path d="M3 4.5l3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.5" /></svg></span>
          </div>
          <div class="aa-ask__actions">
            <MockButton primary>Submit</MockButton>
            <MockButton>Decline</MockButton>
            <MockButton>Not now</MockButton>
          </div>
        </div>
      </Window>
      <div class="aa-ask__after">
        <Card eyebrow="handle() receives" tone="pass">
          <dl class="aa-ask__values">
            <dt>title</dt>
            <dd>Launch notes</dd>
            <dt>body</dt>
            <dd>What shipped this week…</dd>
            <dt>status</dt>
            <dd>draft</dd>
          </dl>
        </Card>
        <Card eyebrow="The model reads" tone="agent">
          <p class="aa-ask__model">The person filled in: title, body, status. Done.</p>
        </Card>
      </div>
    </Columns>
  </div>
</template>

<style>
.aa-ask {
  display: flex;
  flex-direction: column;
  gap: 28px;
}

.aa-ask__form {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.aa-ask__source {
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
}

.aa-ask__message,
.vp-doc .aa-ask__message {
  margin: 0;
  color: var(--aa-ink);
  font-weight: 500;
}

.aa-ask__field {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.aa-ask__name {
  color: var(--aa-ink-2);
  font-size: var(--aa-fs-xs);
}

.aa-ask__name span {
  color: var(--aa-muted);
}

.aa-ask__input {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  min-height: 30px;
  padding: 4px 9px;
  border: 1px solid var(--aa-line-strong);
  border-radius: var(--aa-radius-sm);
  background: var(--aa-surface);
  color: var(--aa-ink);
  font-size: var(--aa-fs-sm);
}

.aa-ask__input.is-area {
  align-items: flex-start;
  min-height: 52px;
}

.aa-ask__input svg {
  flex: none;
  color: var(--aa-muted);
}

.aa-ask__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.aa-ask__after {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.aa-ask__values,
.vp-doc .aa-ask__values {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 2px 12px;
  margin: 0;
}

.aa-ask__values dt {
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  line-height: 20px;
}

.aa-ask__values dd,
.vp-doc .aa-ask__values dd {
  margin: 0;
  min-width: 0;
  color: var(--aa-ink);
  overflow-wrap: anywhere;
}

.vp-doc .aa-ask__model,
.aa-ask__model {
  color: var(--aa-ink);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  line-height: 1.6;
}
</style>
