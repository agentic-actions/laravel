<script setup lang="ts">
/**
 * PipelineFlow: the whole pipeline, in the order of docs/concepts.md "The
 * pipeline", with the answer each step gives when it says no.
 *
 * It composes Flow and Step and adds two things they lack, both in this
 * file: a dashed box for a step only some callers take (step 6 for agents
 * and MCP, the confirmation for an agent's Destructive or External call),
 * and a second box that keeps step 10's number, because concepts.md counts
 * the confirmation and handle() as one step.
 *
 *   <Figure caption="…"><PipelineFlow /></Figure>
 */
import Flow from '../diagrams/Flow.vue'
import Step from '../diagrams/Step.vue'
import Pill from '../diagrams/Pill.vue'
</script>

<template>
  <div class="pl-flow">
    <Flow vertical numbered compact label="The pipeline, in order">
      <Step title="the door" icon="arrow" note="#[Expose], the surface's switch, the agent's toolset" branch="not found: 404" />
      <Step title="the token" icon="key" note="the effect's ability, such as actions:write" branch="not found: 404" />
      <Step title="shouldRegister()" code note="exposure, not authorization" branch="not found: 404" />
      <Step title="tenant membership" icon="team" tone="tenant" note="when the call has a tenant" branch="not a member: 404" />
      <Step title="authorize()" code icon="lock" note="when it takes no input" branch="denied: 403" />
      <Step class="is-some" title="the arguments cut" icon="agent" note="agents and MCP: only the advertised schema" />
      <Step title="input prepared" note="fixed input, conversions, prepareForValidation()" />
      <Step title="validation" icon="check" note="schema() plus rules()" branch="invalid: 422" />
      <Step title="authorize()" code icon="lock" note="when it takes ValidatedInput" branch="denied: 403" />
      <Step class="is-some" title="the person confirms" icon="clock" tone="wait" note="an agent's Destructive or External call" branch="declined: nothing runs" />
      <Step class="is-same" title="handle()" code tone="pass" note="then the output projection and modelReply()" branch="Refusal: 409 by default" />
      <Step title="one event" note="with no input values">
        <span class="pl-flow__events">
          <Pill tone="pass">ActionCompleted</Pill>
          <Pill tone="refuse">ActionRefused</Pill>
          <Pill tone="refuse">ActionFailed</Pill>
        </span>
      </Step>
    </Flow>
    <p class="pl-flow__key">
      <span class="pl-flow__dash" aria-hidden="true" />
      <span>Dashed: only some callers take this step.</span>
    </p>
  </div>
</template>

<style>
.pl-flow .aa-step.is-some .aa-step__box {
  border-style: dashed;
  box-shadow: none;
}

/* concepts.md counts the confirmation and handle() as step 10. */
.pl-flow .aa-step.is-same {
  counter-increment: none;
}

/* On a phone the boxes take the whole width and each answer sits under its
   box, on the right, so a title such as shouldRegister() never breaks. The
   arrow then runs down the left, drawn on the step rather than the box. */
@container (max-width: 520px) {
  .pl-flow.pl-flow .aa-flow.is-vertical {
    grid-template-columns: minmax(0, 1fr);
  }

  .pl-flow.pl-flow .aa-flow.is-vertical > .aa-step {
    isolation: isolate;
  }

  .pl-flow.pl-flow .aa-flow.is-vertical > .aa-step .aa-step__box::after,
  .pl-flow.pl-flow .aa-flow.is-vertical > .aa-step .aa-step__box::before {
    display: none;
  }

  .pl-flow.pl-flow .aa-flow.is-vertical > .aa-step:not(:last-child)::after,
  .pl-flow.pl-flow .aa-flow.is-vertical > .aa-step:not(:last-child)::before {
    content: '';
    position: absolute;
    pointer-events: none;
  }

  .pl-flow.pl-flow .aa-flow.is-vertical > .aa-step:not(:last-child)::after {
    z-index: -1;
    left: 24px;
    top: 0;
    bottom: calc(3px - var(--aa-flow-gap));
    border-left: 1.5px dotted var(--aa-wire);
  }

  .pl-flow.pl-flow .aa-flow.is-vertical > .aa-step:not(:last-child)::before {
    left: 21px;
    bottom: calc(4px - var(--aa-flow-gap));
    width: 6px;
    height: 6px;
    border-top: 1.5px solid var(--aa-wire);
    border-right: 1.5px solid var(--aa-wire);
    transform: rotate(135deg);
  }

  .pl-flow.pl-flow .aa-flow.is-vertical .aa-step__branch {
    grid-column: 1;
    flex-direction: column;
    align-items: flex-end;
    max-width: none;
    padding-right: 14px;
  }

  .pl-flow.pl-flow .aa-flow.is-vertical .aa-step__wire {
    width: 0;
    height: 10px;
    margin-right: 22px;
    border-top: 0;
    border-left: 1.5px dotted var(--tone-line);
  }
}

.pl-flow__events {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 4px;
}

.pl-flow__key {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 18px 0 0;
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
}

.vp-doc .pl-flow__key {
  margin: 18px 0 0;
}

.pl-flow__dash {
  flex: none;
  width: 22px;
  height: 12px;
  border: 1px dashed var(--aa-line-strong);
  border-radius: 4px;
}
</style>
