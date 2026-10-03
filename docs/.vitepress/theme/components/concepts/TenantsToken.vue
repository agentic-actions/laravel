<script setup lang="ts">
/**
 * TenantsToken: an MCP token bound to one tenant with the ability
 * "tenant:{primary key}", beside the three MCP paths it might call. Only its
 * tenant's path lists tools; another tenant's path and the base path list none
 * (docs/mcp.md#tenants, docs/security.md, src/Security/TokenCheck.php).
 *
 * The token sits left with an arrow to the paths, and stacks above them below
 * 560px (Columns). Side by side, the paths take the wider column.
 */
const paths = [
  { url: 'POST mcp/t/acme', detail: "Acme's tenant path", tone: 'pass', status: "Acme's tools" },
  { url: 'POST mcp/t/globex', detail: "Globex's tenant path", tone: 'refuse', status: 'no tools' },
  { url: 'POST mcp/actions', detail: 'the base path', tone: 'refuse', status: 'no tools' },
] as const
</script>

<template>
  <Columns arrows class="aa-tenants-token">
    <Card eyebrow="MCP token" title="Sam's laptop" tone="mcp">
      <span class="aa-tenants-token__abilities">
        <Pill :dot="false">actions:read</Pill>
        <Pill :dot="false">actions:write</Pill>
        <Pill tone="tenant" solid>tenant:1</Pill>
      </span>
      <span class="aa-tenants-token__note">1 is Acme's primary key, never its slug.</span>
    </Card>
    <ul class="aa-tenants-token__paths" aria-label="The MCP paths">
      <li v-for="path in paths" :key="path.url">
        <ListRow boxed mono icon="mcp" :tone="path.tone" :label="path.url" :detail="path.detail" :status="path.status" />
      </li>
    </ul>
  </Columns>
</template>

<style>
@container (min-width: 561px) {
  .aa-tenants-token .aa-columns.is-2 {
    grid-template-columns: minmax(0, 0.8fr) minmax(0, 1.2fr);
  }
}

.aa-tenants-token__abilities {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 4px;
}

.aa-tenants-token__note {
  display: block;
  margin-top: 10px;
  color: var(--aa-muted);
  font-size: var(--aa-fs-sm);
}

.aa-tenants-token__paths,
.vp-doc .aa-tenants-token__paths {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
  margin: 0;
  padding: 0;
  list-style: none;
}

.vp-doc .aa-tenants-token__paths > li + li {
  margin-top: 0;
}

.aa-tenants-token__paths .aa-row.is-boxed {
  box-shadow: var(--aa-shadow);
}
</style>
