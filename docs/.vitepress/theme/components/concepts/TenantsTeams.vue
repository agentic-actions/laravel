<script setup lang="ts">
/**
 * TenantsTeams: two teams, Acme and Globex, and one person, Sam, who belongs to
 * Acme only. Under them, the same tenant-scoped route called by Sam in each team
 * and in a team that does not exist: it runs in Acme, and answers the same 404
 * in Globex as for the unknown team (docs/concepts.md#tenants, docs/security.md).
 *
 * The two teams sit side by side and stack below 480px. The text reads in
 * order: Sam, Acme, Globex, then the three calls. A zero-width space after each
 * slash lets a URL wrap at its segments on a narrow screen.
 */
const teams = [
  { name: 'Acme', member: true, people: ['Sam', 'Lee'] },
  { name: 'Globex', member: false, people: ['Kim', 'Ari'] },
]

const wrap = (url: string): string => url.replaceAll('/', '/\u200B')

const calls = [
  { url: wrap('POST teams/acme/actions/update-post'), detail: 'runs as Sam, inside Acme', tone: 'pass', status: 'runs' },
  { url: wrap('POST teams/globex/actions/update-post'), detail: 'Sam is not a member', tone: 'refuse', status: '404' },
  { url: wrap('POST teams/no-such-team/actions/update-post'), detail: 'no team has this slug', tone: 'refuse', status: '404' },
] as const
</script>

<template>
  <div class="aa-tenants-teams">
    <div class="aa-tenants-teams__who">
      <span class="aa-tenants-teams__avatar" aria-hidden="true">S</span>
      <span class="aa-tenants-teams__name">Sam</span>
      <span class="aa-tenants-teams__note">signed in</span>
    </div>

    <div class="aa-tenants-teams__grid">
      <section
        v-for="team in teams"
        :key="team.name"
        class="aa-tenants-teams__team"
        :class="team.member ? 'aa-tone-tenant is-member' : 'aa-tone-neutral is-stranger'"
        :aria-label="`Team ${team.name}`"
      >
        <div class="aa-tenants-teams__head">
          <span class="aa-tenants-teams__title">
            <Icon name="team" :size="16" class="aa-tenants-teams__icon" />
            {{ team.name }}
          </span>
          <Pill :tone="team.member ? 'tenant' : 'neutral'" :dot="team.member">
            {{ team.member ? 'Sam is a member' : 'Sam is not a member' }}
          </Pill>
        </div>
        <ul class="aa-tenants-teams__people" :aria-label="`${team.name}'s members`">
          <li v-for="person in team.people" :key="person" :class="{ 'is-sam': person === 'Sam' }">
            <span class="aa-tenants-teams__dot" aria-hidden="true">{{ person[0] }}</span>
            {{ person }}
          </li>
        </ul>
      </section>
    </div>

    <div class="aa-tenants-teams__calls">
      <span class="aa-tenants-teams__eyebrow">Sam calls the same action</span>
      <ul aria-label="Sam's calls">
        <li v-for="call in calls" :key="call.url">
          <ListRow boxed mono :tone="call.tone" :label="call.url" :detail="call.detail" :status="call.status" />
        </li>
      </ul>
    </div>
  </div>
</template>

<style>
.aa-tenants-teams {
  container-type: inline-size;
  display: flex;
  flex-direction: column;
  gap: 14px;
  width: 100%;
}

.aa-tenants-teams__who {
  display: flex;
  align-items: center;
  gap: 8px;
  align-self: center;
  padding: 5px 12px 5px 5px;
  border: 1px solid var(--aa-line-strong);
  border-radius: 999px;
  background: var(--aa-surface);
  box-shadow: var(--aa-shadow);
}

.aa-tenants-teams__avatar,
.aa-tenants-teams__dot {
  display: inline-grid;
  flex: none;
  place-items: center;
  border-radius: 50%;
  background: var(--aa-sunken);
  color: var(--aa-ink-2);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
}

.aa-tenants-teams__avatar {
  width: 26px;
  height: 26px;
}

.aa-tenants-teams__name {
  color: var(--aa-ink);
  font-size: var(--aa-fs-md);
  font-weight: 500;
}

.aa-tenants-teams__note {
  color: var(--aa-muted);
  font-size: var(--aa-fs-sm);
}

.aa-tenants-teams__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.aa-tenants-teams__team {
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-width: 0;
  padding: 14px;
  border: 1px solid var(--aa-line-strong);
  border-radius: var(--aa-radius-lg);
  background: var(--aa-surface);
  box-shadow: var(--aa-shadow);
}

.aa-tenants-teams__team.is-member {
  border-color: var(--tone-line);
}

.aa-tenants-teams__team.is-stranger {
  border-style: dashed;
  box-shadow: none;
}

.aa-tenants-teams__head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 6px 10px;
}

.aa-tenants-teams__title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: var(--aa-ink);
  font-size: var(--aa-fs-md);
  font-weight: 500;
}

.aa-tenants-teams__icon {
  color: var(--tone);
}

.aa-tenants-teams__team.is-stranger .aa-tenants-teams__icon {
  color: var(--aa-muted);
}

.aa-tenants-teams__people,
.vp-doc .aa-tenants-teams__people {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.vp-doc .aa-tenants-teams__people > li + li {
  margin-top: 0;
}

.aa-tenants-teams__people li {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 2px 10px 2px 3px;
  border: 1px solid var(--aa-line);
  border-radius: 999px;
  background: var(--aa-ground);
  color: var(--aa-ink-2);
  font-size: var(--aa-fs-sm);
  line-height: 20px;
}

.aa-tenants-teams__dot {
  width: 20px;
  height: 20px;
}

.aa-tenants-teams__people li.is-sam {
  border-color: var(--aa-tenant-line);
  background: var(--aa-tenant-fill);
  color: var(--aa-ink);
}

.aa-tenants-teams__people li.is-sam .aa-tenants-teams__dot {
  background: var(--aa-surface);
  color: var(--aa-tenant);
}

.aa-tenants-teams__calls {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.aa-tenants-teams__eyebrow {
  color: var(--aa-muted);
  font-family: var(--aa-font-mono);
  font-size: var(--aa-fs-xs);
  letter-spacing: var(--aa-tracking-label);
  text-transform: uppercase;
}

.aa-tenants-teams__calls ul,
.vp-doc .aa-tenants-teams__calls ul {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.vp-doc .aa-tenants-teams__calls li + li {
  margin-top: 0;
}

.aa-tenants-teams__calls .aa-row.is-boxed {
  box-shadow: var(--aa-shadow);
}

@container (max-width: 480px) {
  .aa-tenants-teams__grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
