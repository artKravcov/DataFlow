<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
const auth = useAuthStore()
</script>
<template>
	<main class="page">
		<h1>Выберите организацию</h1>
		<p class="muted">Организация определяет границы доступа к проектам и будущим данным.</p>
		<p v-if="!auth.user" class="muted">Загрузка…</p>
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
			<h2>Нет доступных организаций</h2>
			<p>Создание и приглашения появятся в следующем этапе управления организациями.</p>
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
