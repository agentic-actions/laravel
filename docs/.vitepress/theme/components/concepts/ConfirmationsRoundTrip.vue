<script setup lang="ts">
/**
 * ConfirmationsRoundTrip: an agent's Destructive call, from the model's call to
 * handle(), with the two ways out where nothing runs.
 *
 * The order is Runner::preview() (the card is built after both authorize()
 * steps, from the validated input) and docs/copilot.md#confirmations (the
 * person's answer, the card built again just before the call runs, a decline
 * that runs nothing). The pill inside the waiting step is the point of the
 * figure: until the person answers, handle() has not run.
 *
 *   <Figure caption="…"><ConfirmationsRoundTrip /></Figure>
 */
</script>

<template>
  <Flow numbered label="An agent's call to DeletePost, from the call to handle()">
    <Step title="The agent calls DeletePost" icon="agent" tone="agent" note="Destructive, offered through a toolset" />
    <Step title="authorize()" code note="allows the call before any card" branch="denied: refused" />
    <Step title="The card waits" icon="clock" tone="wait" note="built on the server from the action">
      <span class="aa-confirm-trip__pill"><Pill tone="wait" solid>handle() has not run</Pill></span>
    </Step>
    <Step title="The person confirms" icon="user" note="in their own session, once" branch="declined: nothing runs" branch-tone="neutral" />
    <Step title="The card is built again" note="it must read the same" branch="changed: nothing runs" />
    <Step title="handle()" code tone="pass" note="runs as the person, in their tenant" />
  </Flow>
</template>

<style>
.aa-confirm-trip__pill {
  display: block;
  margin-top: 4px;
}
</style>
