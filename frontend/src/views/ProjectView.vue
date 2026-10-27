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
		if (sequence === pipelineLoadSequence) pipelineError.value = 'Could not load pipelines.'
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
		pipelineError.value = 'Could not create pipeline.'
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
				? 'Project was not found or is unavailable.'
				: 'Could not load project.'
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
		} else error.value = 'Could not save changes.'
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
			>← Back to projects</RouterLink
		>
		<p v-if="loading" class="muted">Loading project…</p>
		<section v-else-if="error && !project" class="card">
			<p class="error">{{ error }}</p>
			<button @click="load">Retry</button>
		</section>
		<section v-else-if="project" class="stack">
			<div>
				<h1>{{ project.name }}</h1>
				<p class="muted">Project pipelines and their settings.</p>
			</div>
			<section class="card stack">
				<h2>Pipelines</h2>
				<form v-if="canEdit" class="row" @submit.prevent="createPipeline">
					<label
						>Pipeline name <input v-model="pipelineName" maxlength="255" required
					/></label>
					<button type="submit" :disabled="pipelineCreating">Create pipeline</button>
				</form>
				<p v-if="pipelineLoading">Loading pipelines…</p>
				<p v-else-if="pipelineError" class="error">
					{{ pipelineError }} <button @click="loadPipelines">Retry</button>
				</p>
				<p v-else-if="!pipelines.length" class="muted">No pipelines yet.</p>
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
				<h2>Project settings</h2>
				<ProjectForm
					:model-value="{
						name: project.name,
						description: project.description,
					}"
					:fields="fields"
					:submitting="saving"
					submit-label="Save changes"
					@submit="save"
				/>
				<p v-if="error" class="error">{{ error }}</p>
			</section>
			<section v-else class="card">
				<h2>Read-only</h2>
				<p>Your role does not allow editing this project.</p>
			</section>
		</section>
	</section>
</template>
