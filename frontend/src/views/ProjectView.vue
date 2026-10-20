<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import ProjectForm from '@/components/ProjectForm.vue'
import { ApiError, type FieldErrors, request } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import type { Project, ProjectInput } from '@/lib/types'
import type { Pipeline } from '@/lib/graph'
import { ensureCsrf } from '@/lib/api'
const pipelines = ref<Pipeline[]>([])
const pipelineName = ref('')
const pipelineError = ref('')
const pipelineLoading = ref(false)
const pipelineCreating = ref(false)
let pipelineLoadSequence = 0
async function loadPipelines(): Promise<void> {
	const sequence = ++pipelineLoadSequence
	const path = `/organizations/${organizationId.value}/projects/${projectId.value}/pipelines`
	pipelineLoading.value = true
	pipelineError.value = ''
	pipelines.value = []
	try {
		const items: Pipeline[] = []
		let page = 1
		let batch: Pipeline[]
		do {
			batch = await request<Pipeline[]>(`${path}?page=${page++}`)
			items.push(...batch)
		} while (batch.length === 25)
		if (sequence === pipelineLoadSequence) pipelines.value = items
	} catch {
		if (sequence === pipelineLoadSequence) pipelineError.value = 'Не удалось загрузить потоки.'
	} finally {
		if (sequence === pipelineLoadSequence) pipelineLoading.value = false
	}
}
async function createPipeline(): Promise<void> {
	if (!pipelineName.value.trim() || pipelineCreating.value) return
	pipelineCreating.value = true
	try {
		await ensureCsrf()
		const p = await request<Pipeline>(
			`/organizations/${organizationId.value}/projects/${projectId.value}/pipelines`,
			{ method: 'POST', body: JSON.stringify({ name: pipelineName.value.trim() }) },
		)
		pipelines.value.unshift(p)
		pipelineName.value = ''
	} catch {
		pipelineError.value = 'Не удалось создать поток.'
	} finally {
		pipelineCreating.value = false
	}
}
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
onMounted(() => {
	load()
	loadPipelines()
})
watch([organizationId, projectId], () => {
	load()
	loadPipelines()
})
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
				<p class="muted">Потоки проекта и их настройки.</p>
			</div>
			<section class="card stack">
				<h2>Потоки</h2>
				<form v-if="canEdit" class="row" @submit.prevent="createPipeline">
					<label
						>Название потока <input v-model="pipelineName" maxlength="255" required
					/></label>
					<button type="submit" :disabled="pipelineCreating">Создать поток</button>
				</form>
				<p v-if="pipelineLoading">Загрузка потоков…</p>
				<p v-else-if="pipelineError" class="error">
					{{ pipelineError }} <button @click="loadPipelines">Повторить</button>
				</p>
				<p v-else-if="!pipelines.length" class="muted">Пока нет потоков.</p>
				<RouterLink
					v-for="pipeline in pipelines"
					:key="pipeline.id"
					:to="{
						name: 'pipeline',
						params: { org: organizationId, pipeline: pipeline.id },
					}"
					>{{ pipeline.name }}</RouterLink
				>
			</section>
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
