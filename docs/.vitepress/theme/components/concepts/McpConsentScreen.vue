<script setup lang="ts">
/**
 * McpConsentScreen: the whole consent screen a remote MCP client opens, in the
 * order the package's own page shows it (resources/views/consent.blade.php):
 * the title, each ability in plain words, the tenant, where the answer goes,
 * who is signed in, the warning, then Deny and Allow. The words are
 * lang/en/oauth.php's, filled in as in docs/mcp.md's Connect step 8. For a
 * shorter screen, use MockConsent.
 */
</script>

<template>
  <Window url="example.com/oauth/authorize" class="aa-mcp-consent">
    <p class="aa-mcp-consent__title">Connect Claude to Blog?</p>
    <p class="aa-mcp-consent__muted">Claude will be able to:</p>
    <ListRow icon="check" tone="pass" label="Read what you can see" />
    <ListRow icon="check" tone="pass" label="Create and change what you can change" />
    <ListRow icon="team" tone="tenant" label="Only in Design." />
    <p class="aa-mcp-consent__line">After you answer, you go to claude.ai.</p>
    <p class="aa-mcp-consent__muted">Signed in as you@example.com</p>
    <p class="aa-mcp-consent__muted">Continue only if you started connecting Claude yourself, just now.</p>
    <div class="aa-mcp-consent__actions">
      <MockButton>Deny</MockButton>
      <MockButton primary>Allow</MockButton>
    </div>
  </Window>
</template>

<style>
.aa-mcp-consent__title,
.vp-doc .aa-mcp-consent__title {
  margin: 2px 0 0;
  color: var(--aa-ink);
  font-size: var(--aa-fs-md);
  font-weight: 500;
  line-height: 1.35;
}

.aa-mcp-consent__line,
.vp-doc .aa-mcp-consent__line {
  margin: 0;
  color: var(--aa-ink-2);
  line-height: 1.45;
}

.aa-mcp-consent__muted,
.vp-doc .aa-mcp-consent__muted {
  margin: 0;
  color: var(--aa-muted);
  line-height: 1.45;
}

.aa-mcp-consent .aa-row__label {
  white-space: normal;
}

.aa-mcp-consent__actions {
  display: flex;
  gap: 8px;
  margin-top: 4px;
}

.aa-mcp-consent__actions > * {
  flex: 1;
}
</style>
