<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
const auth = useAuthStore()
</script>
<template>
	<main class="page">
		<h1>Choose an organization</h1>
		<p class="muted">An organization defines access boundaries for projects and data.</p>
		<p v-if="!auth.user" class="muted">Loading…</p>
		<section v-else-if="auth.user.organizations.length" class="stack">
			<RouterLink
				v-for="organization in auth.user.organizations"
				:key="organization.id"
				class="card organization"
				:to="{ name: 'overview', params: { org: organization.id } }"
				><strong>{{ organization.name }}</strong
				><span>{{ organization.role }}</span></RouterLink
			>
		</section>
		<section v-else class="card">
			<h2>No organizations available</h2>
			<p>Creation and invitations will be added in the next organization-management stage.</p>
		</section>
	</main>
</template>
<style scoped>
.organization {
	display: flex;
	justify-content: space-between;
	color: inherit;
	text-decoration: none;
}
.organization:hover {
	border-color: var(--primary);
}
</style>
