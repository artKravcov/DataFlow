<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { ApiError, ensureCsrf, request } from '@/lib/api'
import type { Graph } from '@/lib/graph'

const props = defineProps<{
	orgId: number
	pipelineId: number
	userId: number
	revision: number
	dirty: boolean
	canEdit: boolean
}>()
type Status = 'queued' | 'running' | 'succeeded' | 'failed'
type Summary = { id: number; status: Status; revision: number; created_at: string }
type Execution = Summary & {
	snapshot: { graph: Graph }
	nodes: { snapshot_node_id: string; status: string }[]
	artifacts: { asset_id: number; name: string; size_bytes: number }[]
	error_code: string | null
}
type Pending = { key: string; revision: number }
const history = ref<Summary[]>([])
const execution = ref<Execution | null>(null)
const selectedId = ref<number | null>(null)
const pending = ref<Pending | null>(null)
const error = ref('')
const loading = ref(false)
const starting = ref(false)
const path = computed(
	() => `/organizations/${props.orgId}/pipelines/${props.pipelineId}/executions`,
)
const storageKey = computed(
	() => `dataflow:execution:${props.userId}:${props.orgId}:${props.pipelineId}`,
)
const active = computed(() =>
	history.value.some((item) => item.status === 'queued' || item.status === 'running'),
)
const statuses: Record<string, string> = {
	queued: 'Queued',
	running: 'Running',
	succeeded: 'Succeeded',
	failed: 'Failed',
	pending: 'Pending',
	skipped: 'Skipped',
}
const nodeLabels: Record<string, string> = {
	'csv.source': 'CSV source',
	'transform.filter': 'Filter',
	'transform.map': 'Map / Rename',
	'csv.destination': 'CSV destination',
}
let generation = 0
let refreshSequence = 0
let timer: ReturnType<typeof setTimeout> | undefined

function readPending(): void {
	try {
		const value = JSON.parse(
			sessionStorage.getItem(storageKey.value) ?? 'null',
		) as Pending | null
		pending.value =
			value && typeof value.key === 'string' && Number.isInteger(value.revision)
				? value
				: null
	} catch {
		pending.value = null
	}
}
function persistPending(value: Pending | null): void {
	pending.value = value
	if (value) sessionStorage.setItem(storageKey.value, JSON.stringify(value))
	else sessionStorage.removeItem(storageKey.value)
}
function explain(reason: unknown): string {
	if (reason instanceof ApiError) {
		if (reason.code === 'EXECUTION_ACTIVE')
			return 'This pipeline already has an active run. Refresh the list below.'
		if (reason.code === 'REVISION_CONFLICT')
			return 'The pipeline version has changed. Refresh the page before running it.'
		if (reason.status === 422)
			return 'The pipeline could not be started. Check the saved graph and node settings.'
		if (reason.status === 403) return 'Your role does not allow you to run this pipeline.'
		return 'The request could not be completed. Check pipeline access and try again.'
	}
	return 'Could not reach the server. Please try again.'
}
async function refresh(): Promise<void> {
	const version = generation
	const sequence = ++refreshSequence
	const current = () => version === generation && sequence === refreshSequence
	const org = props.orgId
	const endpoint = path.value
	if (timer) clearTimeout(timer)
	loading.value = true
	try {
		const items = await request<Summary[]>(endpoint)
		if (!current()) return
		history.value = items
		if (!selectedId.value || !items.some((item) => item.id === selectedId.value))
			selectedId.value = items[0]?.id ?? null
		if (selectedId.value) {
			const detail = await request<Execution>(
				`/organizations/${org}/executions/${selectedId.value}`,
			)
			if (!current()) return
			execution.value = detail
		} else execution.value = null
		error.value = ''
	} catch (reason) {
		if (current()) error.value = explain(reason)
	} finally {
		if (current()) {
			loading.value = false
			if (active.value) timer = setTimeout(refresh, 3000)
		}
	}
}
async function start(): Promise<void> {
	if (!props.canEdit || starting.value || (!pending.value && (props.dirty || active.value)))
		return
	const version = generation
	const endpoint = path.value
	const attempt = pending.value ?? { key: crypto.randomUUID(), revision: props.revision }
	try {
		persistPending(attempt)
		starting.value = true
		error.value = ''
		await ensureCsrf()
		const result = await request<{ id: number }>(endpoint, {
			method: 'POST',
			headers: { 'Idempotency-Key': attempt.key },
			body: JSON.stringify({ revision: attempt.revision }),
		})
		if (version !== generation) return
		persistPending(null)
		selectedId.value = result.id
		await refresh()
	} catch (reason) {
		if (version !== generation) return
		if (
			reason instanceof ApiError &&
			(reason.status === 422 ||
				['REVISION_CONFLICT', 'EXECUTION_ACTIVE', 'IDEMPOTENCY_CONFLICT'].includes(
					reason.code,
				))
		)
			persistPending(null)
		error.value = pending.value
			? 'The start response was not received. Retrying will not create a second run.'
			: explain(reason)
	} finally {
		if (version === generation) starting.value = false
	}
}
function select(id: number): void {
	selectedId.value = id
	void refresh()
}
function nodeName(id: string): string {
	const node = execution.value?.snapshot.graph.nodes.find((item) => item.id === id)
	return `${nodeLabels[node?.type ?? ''] ?? 'Node'} · ${id.slice(0, 8)}`
}
watch(
	() => [props.orgId, props.pipelineId, props.userId],
	() => {
		generation++
		if (timer) clearTimeout(timer)
		history.value = []
		execution.value = null
		selectedId.value = null
		starting.value = false
		error.value = ''
		readPending()
		void refresh()
	},
	{ immediate: true },
)
onBeforeUnmount(() => {
	generation++
	if (timer) clearTimeout(timer)
})
</script>

<template>
	<section class="execution-panel" aria-labelledby="execution-title">
		<div class="heading">
			<h2 id="execution-title">Runs</h2>
			<button type="button" class="secondary" :disabled="loading" @click="refresh">
				Refresh
			</button>
		</div>
		<p v-if="dirty" class="muted">
			Save your changes before running. The saved graph version will be used.
		</p>
		<p v-if="pending" class="muted">
			The previous request did not return a response. Retry it to check the result.
		</p>
		<button
			v-if="canEdit"
			type="button"
			:disabled="starting || (!pending && (dirty || active))"
			@click="start"
		>
			{{ pending ? 'Retry start request' : 'Run' }}
		</button>
		<p v-if="active && !pending" class="muted">This pipeline already has an active run.</p>
		<p v-if="error" class="error" role="alert">{{ error }}</p>
		<p v-if="!history.length && !loading" class="muted">No runs yet.</p>
		<div v-if="history.length" class="execution-content">
			<label
				>Recent runs<select
					:value="selectedId"
					@change="select(Number(($event.target as HTMLSelectElement).value))"
				>
					<option v-for="item in history" :key="item.id" :value="item.id">
						#{{ item.id }} · {{ statuses[item.status] ?? item.status }} · version
						{{ item.revision }}
					</option>
				</select></label
			>
			<div v-if="execution" aria-live="polite">
				<p>
					<strong>{{ statuses[execution.status] ?? execution.status }}</strong> · version
					{{ execution.revision }}
				</p>
				<p v-if="execution.error_code" class="error">
					Error code: {{ execution.error_code }}.
					<span v-if="execution.error_code === 'WORKER_LOST'"
						>The outcome of external operations may be unknown.</span
					>
				</p>
				<ul>
					<li v-for="node in execution.nodes" :key="node.snapshot_node_id">
						{{ nodeName(node.snapshot_node_id) }} —
						{{ statuses[node.status] ?? node.status }}
					</li>
				</ul>
				<p v-for="artifact in execution.artifacts" :key="artifact.asset_id">
					<a :href="`/api/v1/organizations/${orgId}/assets/${artifact.asset_id}/download`"
						>Download {{ artifact.name }}</a
					>
					· {{ artifact.size_bytes }} bytes
				</p>
			</div>
		</div>
	</section>
</template>

<style scoped>
.execution-panel {
	background: var(--surface-raised);
	border: 1px solid var(--border);
	border-radius: 12px;
	padding: 16px;
	margin: 16px 0;
	display: grid;
	gap: 10px;
}
.heading {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}
.execution-panel p {
	margin: 0;
}
h2 {
	font-size: 1.1rem;
	margin: 0;
}
.secondary {
	background: #243652;
	color: var(--text);
}
.execution-content {
	margin-top: 4px;
}
label {
	display: grid;
	gap: 6px;
	max-width: 480px;
}
select {
	width: 100%;
}
.error {
	color: var(--danger);
	overflow-wrap: anywhere;
}
</style>
