<script setup lang="ts">
/**
 * MockTests: a test run in a terminal: the caller's test fakes the actions and
 * asserts what ran, and a toolset is pinned (docs/testing.md).
 */
</script>

<template>
  <Window title="Terminal" width="420px" class="aa-mock-tests">
    <span class="aa-mock-tests__cmd">$ vendor/bin/pest</span>
    <ListRow mono icon="check" tone="pass" label="$fake->assertRan(CreatePost::class)" />
    <ListRow mono icon="check" tone="pass" label="$fake->assertNotRan(PublishPost::class)" />
    <ListRow mono icon="check" tone="pass" label="assertToolset('default', ['create-post'])" />
    <span class="aa-mock-tests__sum"><Pill tone="pass" solid>3 passed</Pill></span>
  </Window>
</template>

<style>
.aa-mock-tests__cmd {
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
}
</style>
