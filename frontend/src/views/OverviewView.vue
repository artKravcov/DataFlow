<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { ApiError, requestEnvelope } from '@/lib/api'

const route = useRoute()
const orgId = computed(() => Number(route.params.org))
type Status = 'queued' | 'running' | 'succeeded' | 'failed'
type Item = {
	id: number
	pipeline_id: number
	project_id: number
	project_name: string
	pipeline_name: string
	status: Status
	revision: number
	error_code: string | null
	created_at: string
}
const items = ref<Item[]>([])
const status = ref<Status | ''>('')
const loading = ref(false)
const error = ref('')
const page = ref(1)
const lastPage = ref(1)
const statuses: Record<Status, string> = {
	queued: 'В очереди',
	running: 'Выполняется',
	succeeded: 'Завершён',
	failed: 'Ошибка',
}
let sequence = 0
let timer: ReturnType<typeof setTimeout> | undefined
const active = computed(() =>
	items.value.some((item) => item.status === 'queued' || item.status === 'running'),
)

function displayDate(value: string): string {
	const date = new Date(value)
	return Number.isNaN(date.getTime()) ? value : date.toLocaleString('ru-RU')
}
async function load(): Promise<void> {
	const current = ++sequence
	if (timer) clearTimeout(timer)
	loading.value = true
	error.value = ''
	try {
		const params = new URLSearchParams({ page: String(page.value) })
		if (status.value) params.set('status', status.value)
		const result = await requestEnvelope<Item[]>(
			`/organizations/${orgId.value}/executions?${params}`,
		)
		if (current !== sequence) return
		items.value = result.data
		lastPage.value = result.meta?.last_page ?? 1
	} catch (reason) {
		if (current !== sequence) return
		error.value =
			reason instanceof ApiError && reason.status === 404
				? 'Организация недоступна.'
				: 'Не удалось загрузить историю запусков.'
	} finally {
		if (current === sequence) {
			loading.value = false
			if (active.value) timer = setTimeout(load, 3000)
		}
	}
}
watch(
	[orgId, status],
	() => {
		page.value = 1
		void load()
	},
	{ immediate: true },
)
onBeforeUnmount(() => {
	sequence++
	if (timer) clearTimeout(timer)
})
function changePage(next: number): void {
	if (next < 1 || next > lastPage.value || loading.value) return
	page.value = next
	void load()
}
</script>

<template>
	<section class="page">
		<div class="title-row">
			<div>
				<h1>Обзор</h1>
				<p class="muted">Последние запуски всех потоков организации.</p>
			</div>
			<button class="secondary" :disabled="loading" @click="load">Обновить</button>
		</div>
		<section class="card stack">
			<label
				>Статус<select v-model="status">
					<option value="">Все статусы</option>
					<option value="queued">В очереди</option>
					<option value="running">Выполняется</option>
					<option value="succeeded">Завершён</option>
					<option value="failed">Ошибка</option>
				</select></label
			>
			<p v-if="error" class="error" role="alert">{{ error }}</p>
			<p v-else-if="loading && !items.length" class="muted">Загрузка запусков…</p>
			<p v-else-if="!items.length" class="muted">Запусков с выбранным статусом пока нет.</p>
			<ul v-else class="history">
				<li v-for="item in items" :key="item.id">
					<RouterLink
						:to="{
							name: 'pipeline',
							params: { org: orgId, pipeline: item.pipeline_id },
						}"
						><strong>{{ item.pipeline_name }}</strong> ·
						{{ item.project_name }}</RouterLink
					><span
						>#{{ item.id }} · {{ statuses[item.status] }} ·
						{{ displayDate(item.created_at) }}</span
					><span v-if="item.error_code" class="error"
						>Код ошибки: {{ item.error_code }}</span
					>
				</li>
			</ul>
			<nav v-if="lastPage > 1" class="pagination" aria-label="Страницы истории запусков">
				<button
					class="secondary"
					:disabled="loading || page === 1"
					@click="changePage(page - 1)"
				>
					Назад
				</button>
				<span>Страница {{ page }} из {{ lastPage }}</span>
				<button
					class="secondary"
					:disabled="loading || page === lastPage"
					@click="changePage(page + 1)"
				>
					Далее
				</button>
			</nav>
			<RouterLink :to="{ name: 'projects', params: { org: orgId } }"
				>Перейти к проектам</RouterLink
			>
		</section>
	</section>
</template>

<style scoped>
.title-row {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
}
h1 {
	margin-bottom: 4px;
}
.secondary {
	background: #243652;
	color: var(--text);
}
.history {
	list-style: none;
	padding: 0;
	margin: 0;
	display: grid;
	gap: 10px;
}
.history li {
	display: grid;
	gap: 3px;
	border-bottom: 1px solid var(--border);
	padding-bottom: 10px;
}
.history span {
	color: var(--muted);
	font-size: 0.9rem;
}
.history .error {
	color: var(--danger);
}
.pagination {
	display: flex;
	align-items: center;
	gap: 10px;
}
.pagination span {
	color: var(--muted);
}
</style>
