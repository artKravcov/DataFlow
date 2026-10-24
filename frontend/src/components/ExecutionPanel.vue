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
	queued: 'В очереди',
	running: 'Выполняется',
	succeeded: 'Завершён',
	failed: 'Ошибка',
	pending: 'Ожидает',
	skipped: 'Пропущен',
}
const nodeLabels: Record<string, string> = {
	'csv.source': 'CSV источник',
	'transform.filter': 'Фильтр',
	'transform.map': 'Сопоставление',
	'csv.destination': 'CSV результат',
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
			return 'У этого потока уже есть активный запуск. Обновите список ниже.'
		if (reason.code === 'REVISION_CONFLICT')
			return 'Версия потока изменилась. Обновите страницу перед запуском.'
		if (reason.status === 422)
			return 'Не удалось запустить поток. Проверьте сохранённый граф и настройки блоков.'
		if (reason.status === 403) return 'Ваша роль не позволяет запускать поток.'
		return 'Не удалось выполнить запрос. Проверьте доступ к потоку и повторите попытку.'
	}
	return 'Не удалось связаться с сервером. Повторите попытку.'
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
			? 'Ответ о запуске не получен. Повторите запрос: это не создаст второй запуск.'
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
	return `${nodeLabels[node?.type ?? ''] ?? 'Блок'} · ${id.slice(0, 8)}`
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
			<h2 id="execution-title">Запуски</h2>
			<button type="button" class="secondary" :disabled="loading" @click="refresh">
				Обновить
			</button>
		</div>
		<p v-if="dirty" class="muted">
			Перед запуском сохраните изменения. Запускается сохранённая версия графа.
		</p>
		<p v-if="pending" class="muted">
			Ответ на предыдущий запрос не получен. Повторите его, чтобы узнать результат.
		</p>
		<button
			v-if="canEdit"
			type="button"
			:disabled="starting || (!pending && (dirty || active))"
			@click="start"
		>
			{{ pending ? 'Повторить запрос запуска' : 'Запустить' }}
		</button>
		<p v-if="active && !pending" class="muted">У потока уже есть активный запуск.</p>
		<p v-if="error" class="error" role="alert">{{ error }}</p>
		<p v-if="!history.length && !loading" class="muted">Запусков пока нет.</p>
		<div v-if="history.length" class="execution-content">
			<label
				>Последние запуски<select
					:value="selectedId"
					@change="select(Number(($event.target as HTMLSelectElement).value))"
				>
					<option v-for="item in history" :key="item.id" :value="item.id">
						#{{ item.id }} · {{ statuses[item.status] ?? item.status }} · версия
						{{ item.revision }}
					</option>
				</select></label
			>
			<div v-if="execution" aria-live="polite">
				<p>
					<strong>{{ statuses[execution.status] ?? execution.status }}</strong> · версия
					{{ execution.revision }}
				</p>
				<p v-if="execution.error_code" class="error">
					Код ошибки: {{ execution.error_code }}.
					<span v-if="execution.error_code === 'WORKER_LOST'"
						>Результат внешних операций может быть неизвестен.</span
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
						>Скачать {{ artifact.name }}</a
					>
					· {{ artifact.size_bytes }} байт
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
