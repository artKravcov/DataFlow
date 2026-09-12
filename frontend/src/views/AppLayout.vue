<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const organization = computed(() => auth.organizationById(Number(route.params.org)))

async function logout(): Promise<void> {
	await auth.logout()
	await router.push({ name: 'login' })
}
</script>

<template>
	<main class="shell">
		<header>
			<RouterLink class="brand" :to="{ name: 'organizations' }">DataFlow</RouterLink>
			<nav v-if="organization">
				<RouterLink :to="{ name: 'overview', params: { org: organization.id } }"
					>Обзор</RouterLink
				>
				<RouterLink :to="{ name: 'projects', params: { org: organization.id } }"
					>Проекты</RouterLink
				>
			</nav>
			<div class="account">
				<span>{{ organization?.name }} · {{ organization?.role }}</span
				><button class="link" @click="logout">Выйти</button>
			</div>
		</header>
		<RouterView />
	</main>
</template>

<style>
:root {
	color: #1f2937;
	background: #f8fafc;
	font-family: Inter, system-ui, sans-serif;
}
* {
	box-sizing: border-box;
}
body {
	margin: 0;
}
button,
input,
textarea {
	font: inherit;
}
button {
	cursor: pointer;
}
.shell {
	max-width: 960px;
	margin: auto;
	padding: 0 24px 48px;
}
header {
	min-height: 68px;
	display: flex;
	align-items: center;
	gap: 28px;
	border-bottom: 1px solid #e2e8f0;
	margin-bottom: 36px;
}
.brand {
	font-weight: 800;
	color: #0f172a;
	text-decoration: none;
}
nav {
	display: flex;
	gap: 16px;
}
nav a {
	color: #475569;
	text-decoration: none;
}
nav a.router-link-active {
	color: #0f172a;
	font-weight: 700;
}
.account {
	margin-left: auto;
	display: flex;
	gap: 12px;
	align-items: center;
	color: #64748b;
	font-size: 0.9rem;
}
.page {
	max-width: 760px;
	margin: auto;
}
.card {
	background: white;
	border: 1px solid #e2e8f0;
	border-radius: 10px;
	padding: 24px;
}
.stack {
	display: grid;
	gap: 16px;
}
label {
	display: grid;
	gap: 6px;
	font-weight: 600;
}
input,
textarea {
	border: 1px solid #94a3b8;
	border-radius: 6px;
	padding: 9px;
}
textarea {
	min-height: 96px;
	resize: vertical;
}
button {
	border: 0;
	border-radius: 6px;
	padding: 9px 14px;
	background: #0f766e;
	color: white;
	font-weight: 650;
}
button:disabled {
	opacity: 0.55;
	cursor: not-allowed;
}
.link {
	background: none;
	padding: 0;
	color: #0f766e;
}
.error {
	color: #b91c1c;
}
.muted {
	color: #64748b;
}
.field-error {
	color: #b91c1c;
	font-size: 0.85rem;
	font-weight: 400;
}
.row {
	display: flex;
	align-items: center;
	gap: 12px;
}
.spread {
	justify-content: space-between;
}
</style>
