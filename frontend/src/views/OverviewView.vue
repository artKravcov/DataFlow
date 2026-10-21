<script setup lang="ts">
import { RouterLink, useRoute } from 'vue-router'
const route = useRoute()
</script>
<template>
	<section class="page">
		<h1>Обзор</h1>
		<section class="card">
			<h2>Запуски потоков</h2>
			<p class="muted">
				Откройте поток в проекте, чтобы запустить его и посмотреть последние результаты.
			</p>
			<RouterLink :to="{ name: 'projects', params: { org: route.params.org } }"
				>Перейти к проектам</RouterLink
			>
		</section>
	</section>
</template>
