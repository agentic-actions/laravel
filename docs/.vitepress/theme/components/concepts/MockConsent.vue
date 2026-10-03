<script setup lang="ts">
/**
 * MockConsent: the OAuth consent screen a remote MCP client opens. Its words
 * are the package's own (lang/en/oauth.php): the title, each ability in plain
 * words, the tenant line, Deny and Allow (docs/mcp.md#what-the-person-sees).
 */
</script>

<template>
  <Window url="example.com/oauth/authorize" width="420px" class="aa-mock-consent">
    <div class="aa-mock-consent__head">
      <Pill tone="mcp">MCP client</Pill>
    </div>
    <p class="aa-mock-consent__title">Connect Assistant to Blog?</p>
    <p class="aa-mock-consent__can">Assistant will be able to:</p>
    <ListRow icon="check" tone="pass" label="Read what you can see" />
    <ListRow icon="check" tone="pass" label="Create and change what you can change" />
    <ListRow icon="team" tone="tenant" label="Only in Design." />
    <div class="aa-mock-consent__actions">
      <MockButton>Deny</MockButton>
      <MockButton primary>Allow</MockButton>
    </div>
  </Window>
</template>

<style>
.aa-mock-consent__title,
.vp-doc .aa-mock-consent__title {
  margin: 2px 0 0;
  color: var(--aa-ink);
  font-size: var(--aa-fs-md);
  font-weight: 500;
}

.aa-mock-consent__can,
.vp-doc .aa-mock-consent__can {
  margin: 0;
  color: var(--aa-muted);
}

.aa-mock-consent__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 4px;
}
</style>
