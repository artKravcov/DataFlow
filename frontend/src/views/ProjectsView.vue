<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import ProjectForm from '@/components/ProjectForm.vue'
import { ApiError, type FieldErrors, request } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import type { Project, ProjectInput } from '@/lib/types'
const route = useRoute()
const auth = useAuthStore()
const projects = ref<Project[]>([])
const loading = ref(false)
const error = ref('')
const fields = ref<FieldErrors>({})
const creating = ref(false)
const showCreate = ref(false)
const organizationId = computed(() => Number(route.params.org))
const organization = computed(() => auth.organizationById(organizationId.value))
const canEdit = computed(
	() => organization.value?.role === 'owner' || organization.value?.role === 'editor',
)

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	projects.value = []
	try {
		projects.value = await request<Project[]>(`/organizations/${organizationId.value}/projects`)
	} catch (reason) {
		error.value =
			reason instanceof ApiError && reason.status === 403
				? 'Your role only allows viewing projects.'
				: 'Could not load projects.'
	} finally {
		loading.value = false
	}
}

async function create(input: ProjectInput): Promise<void> {
	creating.value = true
	fields.value = {}
	try {
		const project = await request<Project>(`/organizations/${organizationId.value}/projects`, {
			method: 'POST',
			body: JSON.stringify(input),
		})
		projects.value.unshift(project)
		showCreate.value = false
	} catch (reason) {
		if (reason instanceof ApiError) {
			fields.value = reason.fields
			error.value = reason.message
		} else error.value = 'Could not create project.'
	} finally {
		creating.value = false
	}
}
onMounted(load)
watch(organizationId, load)
</script>
<template>
	<section class="page">
		<div class="row spread">
			<div>
				<h1>Projects</h1>
				<p class="muted">Projects in the selected organization.</p>
			</div>
			<button v-if="canEdit" @click="showCreate = !showCreate">
				{{ showCreate ? 'Cancel' : 'New project' }}
			</button>
		</div>
		<section v-if="showCreate" class="card">
			<h2>New project</h2>
			<ProjectForm
				:model-value="{ name: '', description: null }"
				:fields="fields"
				:submitting="creating"
				submit-label="Create project"
				@submit="create"
			/>
		</section>
		<p v-if="loading" class="muted">Loading projects…</p>
		<section v-else-if="error" class="card">
			<p class="error">{{ error }}</p>
			<button @click="load">Retry</button>
		</section>
		<section v-else-if="!projects.length" class="card">
			<h2>No projects yet</h2>
			<p>
				{{
					canEdit
						? 'Create your first project to build a pipeline in it.'
						: 'Your role allows viewing, but there are no accessible projects in this organization yet.'
				}}
			</p>
		</section>
		<section v-else class="stack">
			<RouterLink
				v-for="project in projects"
				:key="project.id"
				class="card project"
				:to="{
					name: 'project',
					params: { org: organizationId, project: project.id },
				}"
				><strong>{{ project.name }}</strong
				><span class="muted">{{
					project.description || 'No description'
				}}</span></RouterLink
			>
		</section>
	</section>
</template>
<style scoped>
.project {
	display: grid;
	gap: 6px;
	color: inherit;
	text-decoration: none;
}
.project:hover {
	border-color: var(--primary);
}
</style>
