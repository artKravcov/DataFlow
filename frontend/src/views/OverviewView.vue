<script setup lang="ts">
import { RouterLink, useRoute } from 'vue-router'
const route = useRoute()
</script>
<template>
	<section class="page">
		<h1>Обзор</h1>
		<section class="card">
			<h2>Запусков пока нет</h2>
			<p class="muted">
				Execution API ещё не реализован, поэтому здесь нет вымышленных запусков или метрик.
			</p>
			<RouterLink :to="{ name: 'projects', params: { org: route.params.org } }"
				>Перейти к проектам</RouterLink
			>
		</section>
	</section>
</template>
