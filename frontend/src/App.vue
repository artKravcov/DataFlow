<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import { RouterView } from 'vue-router'
import { locale, localeOptions, startLocalization } from '@/lib/i18n'

let stopLocalization: (() => void) | undefined
onMounted(() => {
	stopLocalization = startLocalization()
})
onBeforeUnmount(() => stopLocalization?.())
</script>

<template>
	<label class="language-switcher">
		<span>Language</span>
		<select v-model="locale" aria-label="Language">
			<option v-for="option in localeOptions" :key="option.value" :value="option.value">
				{{ option.label }}
			</option>
		</select>
	</label>
	<RouterView />
</template>

<style scoped>
.language-switcher {
	position: fixed;
	top: 16px;
	right: 18px;
	z-index: 20;
	display: flex;
	align-items: center;
	gap: 7px;
	padding: 6px 9px;
	background: #17243a;
	border: 1px solid #48617f;
	border-radius: 8px;
	color: #e5edf9;
	font-size: 0.82rem;
}
.language-switcher select {
	min-width: 92px;
	padding: 4px 6px;
	color: #e5edf9;
	background: #0d1727;
	border: 0;
}
@media (max-width: 720px) {
	.language-switcher {
		top: 8px;
		right: 8px;
	}
	.language-switcher span {
		display: none;
	}
}
</style>
