<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import ProjectForm from '@/components/ProjectForm.vue'
import { ApiError, type FieldErrors, request } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import type { Project, ProjectInput } from '@/lib/types'
const route = useRoute()
const auth = useAuthStore()
const project = ref<Project | null>(null)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const fields = ref<FieldErrors>({})
const organizationId = computed(() => Number(route.params.org))
const projectId = computed(() => Number(route.params.project))
const canEdit = computed(() => {
	const role = auth.organizationById(organizationId.value)?.role
	return role === 'owner' || role === 'editor'
})

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	project.value = null
	try {
		project.value = await request<Project>(
			`/organizations/${organizationId.value}/projects/${projectId.value}`,
		)
	} catch (reason) {
		error.value =
			reason instanceof ApiError && reason.status === 404
				? 'Проект не найден или недоступен.'
				: 'Не удалось загрузить проект.'
	} finally {
		loading.value = false
	}
}

async function save(input: ProjectInput): Promise<void> {
	saving.value = true
	fields.value = {}
	error.value = ''
	try {
		project.value = await request<Project>(
			`/organizations/${organizationId.value}/projects/${projectId.value}`,
			{ method: 'PATCH', body: JSON.stringify(input) },
		)
	} catch (reason) {
		if (reason instanceof ApiError) {
			fields.value = reason.fields
			error.value = reason.message
		} else error.value = 'Не удалось сохранить изменения.'
	} finally {
		saving.value = false
	}
}
onMounted(load)
watch([organizationId, projectId], load)
</script>
<template>
	<section class="page">
		<RouterLink :to="{ name: 'projects', params: { org: organizationId } }"
			>← К проектам</RouterLink
		>
		<p v-if="loading" class="muted">Загрузка проекта…</p>
		<section v-else-if="error && !project" class="card">
			<p class="error">{{ error }}</p>
			<button @click="load">Повторить</button>
		</section>
		<section v-else-if="project" class="stack">
			<div>
				<h1>{{ project.name }}</h1>
				<p class="muted">Pipeline и executions будут добавлены в последующих этапах.</p>
			</div>
			<section v-if="canEdit" class="card">
				<h2>Настройки проекта</h2>
				<ProjectForm
					:model-value="{
						name: project.name,
						description: project.description,
					}"
					:fields="fields"
					:submitting="saving"
					submit-label="Сохранить изменения"
					@submit="save"
				/>
				<p v-if="error" class="error">{{ error }}</p>
			</section>
			<section v-else class="card">
				<h2>Только просмотр</h2>
				<p>Ваша роль не позволяет менять этот проект.</p>
			</section>
		</section>
	</section>
</template>
