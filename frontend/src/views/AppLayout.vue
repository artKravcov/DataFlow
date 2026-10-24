<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const organization = computed(() => auth.organizationById(Number(route.params.org)))

async function logout(): Promise<void> {
	const event = new Event('dataflow-before-logout', { cancelable: true })
	if (!window.dispatchEvent(event)) return
	try {
		await auth.logout()
		await router.push({ name: 'login' })
	} finally {
		window.dispatchEvent(new Event('dataflow-logout-finished'))
	}
}
</script>

<template>
	<main class="shell">
		<header class="topbar">
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
	color: #e5edf9;
	background: #09111f;
	font-family: Inter, ui-sans-serif, system-ui, sans-serif;
	font-synthesis: none;
	--surface: #111c2e;
	--surface-raised: #17243a;
	--surface-input: #0d1727;
	--border: #2b3b54;
	--border-strong: #48617f;
	--text: #e5edf9;
	--muted: #9dafc7;
	--primary: #3b82f6;
	--primary-hover: #60a5fa;
	--link: #7dd3fc;
	--danger: #fb7185;
}
* {
	box-sizing: border-box;
}
body {
	margin: 0;
	min-width: 320px;
	min-height: 100vh;
	background: radial-gradient(circle at top left, #172b4d 0, #09111f 42rem);
}
button,
input,
textarea,
select {
	font: inherit;
}
button {
	cursor: pointer;
}
a {
	color: var(--link);
}
.shell {
	max-width: 1240px;
	margin: auto;
	padding: 0 28px 56px;
}
.topbar {
	min-height: 72px;
	display: flex;
	align-items: center;
	gap: 32px;
	border-bottom: 1px solid var(--border);
	margin-bottom: 28px;
}
.brand {
	font-weight: 800;
	letter-spacing: -0.04em;
	font-size: 1.25rem;
	color: var(--text);
	text-decoration: none;
}
nav {
	display: flex;
	gap: 8px;
}
nav a {
	color: var(--muted);
	text-decoration: none;
	padding: 8px 11px;
	border-radius: 7px;
}
nav a.router-link-active {
	color: var(--text);
	background: var(--surface-raised);
	font-weight: 700;
}
.account {
	margin-left: auto;
	display: flex;
	gap: 12px;
	align-items: center;
	color: var(--muted);
	font-size: 0.9rem;
}
.page {
	max-width: 860px;
	margin: auto;
}
.card {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: 12px;
	padding: 24px;
	box-shadow: 0 18px 48px rgb(0 0 0 / 0.16);
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
textarea,
select {
	min-width: 0;
	max-width: 100%;
	color: var(--text);
	background: var(--surface-input);
	border: 1px solid var(--border-strong);
	border-radius: 7px;
	padding: 9px;
}
input[type='file'] {
	width: 100%;
	font-size: 0.8rem;
}
textarea {
	min-height: 96px;
	resize: vertical;
}
button {
	border: 0;
	border-radius: 7px;
	padding: 9px 14px;
	background: var(--primary);
	color: #fff;
	font-weight: 650;
	transition:
		background 0.15s ease,
		transform 0.15s ease;
}
button:not(:disabled):hover {
	background: var(--primary-hover);
	transform: translateY(-1px);
}
button:disabled {
	opacity: 0.55;
	cursor: not-allowed;
}
.link {
	background: none;
	padding: 0;
	color: var(--link);
}
.error {
	color: var(--danger);
}
.muted {
	color: var(--muted);
}
.field-error {
	color: var(--danger);
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
@media (max-width: 720px) {
	.shell {
		padding: 0 16px 36px;
	}
	.topbar {
		align-items: flex-start;
		flex-wrap: wrap;
		gap: 10px 18px;
		padding: 16px 0;
	}
	.account {
		width: 100%;
		margin-left: 0;
	}
}
</style>
