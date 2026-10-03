<script setup lang="ts">
/**
 * McpOAuthFlow: how a remote client (a Claude custom connector, ChatGPT)
 * connects once the app names a Passport guard in mcp.middleware, from the URL
 * the person pastes to the connection that a revoke() ends
 * (docs/mcp.md#connect-claude-chatgpt-and-other-remote-clients-oauth,
 * #what-a-connection-reaches, #connected-apps). Put it beside
 * McpConsentScreen in Columns.
 */
</script>

<template>
  <Flow vertical numbered label="A remote client connects">
    <Step title="The person adds the URL" tone="mcp" note="a custom connector, with mcp/t/design" />
    <Step title="401 names the scopes" note="and the path's OAuth metadata" />
    <Step title="The client registers" note="then the person signs in" />
    <Step title="Consent screen" tone="wait" note="the person approves one URL" />
    <Step title="One connection" tone="pass" note="its token reaches only that URL" />
    <Step title="revoke()" code tone="refuse" note="the next call answers 401" />
  </Flow>
</template>
