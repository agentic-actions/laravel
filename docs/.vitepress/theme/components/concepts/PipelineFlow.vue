<script setup lang="ts">
/**
 * PipelineFlow: the whole pipeline, in the order of docs/concepts.md "The
 * pipeline", with the answer each step gives when it says no.
 *
 * It composes Flow and Step and adds two things they lack, both in this
 * file: a dashed box for a step only some callers take (step 6 for agents
 * and MCP, the confirmation for an agent's Destructive or External call),
 * and the numbers 10a and 10b for the confirmation and handle(), because
 * concepts.md counts the two as one step.
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
      <Step class="is-some is-first" title="the person confirms" icon="clock" tone="wait" note="an agent's Destructive or External call" branch="declined: nothing runs" branch-tone="neutral" />
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
  border-color: var(--aa-wire);
  box-shadow: none;
}

.pl-flow .aa-step.is-some.aa-tone-wait .aa-step__box {
  border-color: var(--aa-wait);
}

/* concepts.md counts the confirmation and handle() as step 10: 10a and 10b. */
.pl-flow .aa-step.is-same {
  counter-increment: none;
}

.pl-flow .aa-flow.is-numbered .aa-step.is-first .aa-step__title::before {
  content: counter(aa-step, decimal-leading-zero) 'a';
}

.pl-flow .aa-flow.is-numbered .aa-step.is-same .aa-step__title::before {
  content: counter(aa-step, decimal-leading-zero) 'b';
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
  border: 1px dashed var(--aa-wire);
  border-radius: 4px;
}
</style>
