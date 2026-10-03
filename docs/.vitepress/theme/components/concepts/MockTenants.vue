<script setup lang="ts">
/**
 * MockTenants: a team switcher and two calls of the same tenant-scoped route,
 * one in a team the person belongs to and one in a team they do not, which
 * answers 404 (docs/concepts.md#tenants).
 */
</script>

<template>
  <Window url="example.com/teams/design/posts" width="420px" class="aa-mock-tenants">
    <div class="aa-mock-tenants__switcher">
      <span class="aa-mock-tenants__label">Team</span>
      <div class="aa-mock-tenants__menu">
        <ListRow icon="team" tone="tenant" label="Design" status="current" />
        <ListRow icon="team" label="Research" />
      </div>
    </div>
    <ListRow boxed mono tone="pass" label="/teams/design/actions/create-post" detail="a member" status="runs" />
    <ListRow boxed mono tone="refuse" label="/teams/sales/actions/create-post" detail="not a member" status="404" />
  </Window>
</template>

<style>
.aa-mock-tenants__switcher {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 4px;
}

.aa-mock-tenants__label {
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  letter-spacing: var(--aa-tracking-label);
  text-transform: uppercase;
}

.aa-mock-tenants__menu {
  padding: 4px 10px;
  border: 1px solid var(--aa-line);
  border-radius: var(--aa-radius-sm);
  background: var(--aa-ground);
}
</style>
