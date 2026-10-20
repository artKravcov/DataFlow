<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate, RouterLink, useRoute } from 'vue-router'
import { VueFlow, type Connection, type NodeDragEvent } from '@vue-flow/core'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import { ApiError, ensureCsrf, request } from '@/lib/api'
import type { Graph, GraphNode, NodeDefinition, Pipeline, SchemaField } from '@/lib/graph'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()
const orgId = computed(() => Number(route.params.org))
const pipelineId = computed(() => Number(route.params.pipeline))
const canEdit = computed(() =>
	['owner', 'editor'].includes(auth.organizationById(orgId.value)?.role ?? ''),
)
const pipeline = ref<Pipeline | null>(null)
const definitions = ref<NodeDefinition[]>([])
const graph = ref<Graph>({ schema_version: 1, nodes: [], edges: [] })
const saved = ref('')
const selectedId = ref('')
const error = ref('')
const notices = ref<string[]>([])
const loading = ref(false)
const busy = ref(false)
const conflict = ref(false)
let loadSequence = 0
let logoutApproved = false
const from = ref('')
const to = ref('')
const selected = computed(() => graph.value.nodes.find((node) => node.id === selectedId.value))
const dirty = computed(() => !!saved.value && JSON.stringify(graph.value) !== saved.value)
const url = computed(() => `/organizations/${orgId.value}/pipelines/${pipelineId.value}`)
const definition = (type: string) => definitions.value.find((item) => item.type === type)
const labels: Record<string, string> = {
	'csv.source': 'CSV источник',
	'transform.filter': 'Фильтр',
	'transform.map': 'Сопоставление',
	'csv.destination': 'CSV результат',
}
const display = (node: GraphNode) => labels[node.type] ?? definition(node.type)?.label ?? node.type
const flowNodes = computed(() =>
	graph.value.nodes.map((node) => ({
		id: node.id,
		label: display(node),
		position: { ...node.position },
		type:
			definition(node.type)?.category === 'source'
				? 'input'
				: definition(node.type)?.category === 'destination'
					? 'output'
					: 'default',
		draggable: canEdit.value && !busy.value,
	})),
)
const flowEdges = computed(() =>
	graph.value.edges.map((edge) => ({ id: edge.id, source: edge.source, target: edge.target })),
)
const selectedSchema = computed(() => (selected.value ? inputSchema(selected.value.id) : null))
const outputSchema = computed(() =>
	selected.value ? schemaFor(selected.value.id, new Set()) : null,
)
const mappings = computed(() => {
	if (!selected.value || selected.value.type !== 'transform.map') return []
	const config = selected.value.config
	return Array.isArray(config.mappings)
		? (config.mappings as { source: string; target: string }[])
		: []
})
function addMapping(): void {
	if (!selected.value) return
	if (!Array.isArray(selected.value.config.mappings)) selected.value.config.mappings = []
	;(selected.value.config.mappings as { source: string; target: string }[]).push({
		source: '',
		target: '',
	})
}
function removeMapping(index: number): void {
	if (!selected.value) return
	mappings.value.splice(index, 1)
	if (!mappings.value.length) delete selected.value.config.mappings
}
function inputSchema(id: string): SchemaField[] | null {
	const edge = graph.value.edges.find((item) => item.target === id)
	return edge ? schemaFor(edge.source, new Set()) : null
}
function schemaFor(id: string, seen: Set<string>): SchemaField[] | null {
	if (seen.has(id)) return null
	seen.add(id)
	const node = graph.value.nodes.find((item) => item.id === id)
	if (!node) return null
	if (node.type === 'csv.source') return node.schema_hints ?? null
	const parent = graph.value.edges.find((edge) => edge.target === id)
	const schema = parent ? schemaFor(parent.source, seen) : null
	if (!schema || node.type !== 'transform.map') return schema
	const rows = node.config.mappings as { source: string; target: string }[] | undefined
	if (
		!rows?.length ||
		rows.some((row) => !row.target || !schema.some((field) => field.name === row.source))
	)
		return null
	return rows.map((row) => ({
		...schema.find((field) => field.name === row.source)!,
		name: row.target,
	}))
}
function connectionError(source: string, target: string): string {
	const a = graph.value.nodes.find((node) => node.id === source)
	const b = graph.value.nodes.find((node) => node.id === target)
	if (!a || !b) return 'Выберите выход и вход.'
	const output = definition(a.type)?.outputs.records
	const input = definition(b.type)?.inputs.records
	if (!output || !input || output !== input) return 'Порты этих блоков несовместимы.'
	if (graph.value.edges.some((edge) => edge.target === target)) return 'Этот вход уже занят.'
	const stack = [target]
	const visited = new Set<string>()
	while (stack.length) {
		const id = stack.pop()!
		if (id === source) return 'Связь создаёт цикл.'
		if (visited.has(id)) continue
		visited.add(id)
		stack.push(
			...graph.value.edges.filter((edge) => edge.source === id).map((edge) => edge.target),
		)
	}
	return ''
}
function connect(source: string, target: string): void {
	if (!canEdit.value || busy.value) return
	const reason = connectionError(source, target)
	if (reason) {
		error.value = reason
		return
	}
	if (graph.value.edges.length >= 100) {
		error.value = 'Достигнут предел: 100 связей.'
		return
	}
	graph.value.edges.push({
		id: crypto.randomUUID(),
		source,
		source_port: 'records',
		target,
		target_port: 'records',
	})
	error.value = ''
}
function onConnect(connection: Connection): void {
	if (connection.source && connection.target) connect(connection.source, connection.target)
}
function addNode(def: NodeDefinition): void {
	if (!canEdit.value || busy.value) return
	if (graph.value.nodes.length >= 50) {
		error.value = 'Достигнут предел: 50 блоков.'
		return
	}
	const node: GraphNode = {
		id: crypto.randomUUID(),
		type: def.type,
		type_version: def.type_version,
		position: {
			x: 40 + (graph.value.nodes.length % 3) * 210,
			y: 50 + Math.floor(graph.value.nodes.length / 3) * 110,
		},
		config: {},
	}
	graph.value.nodes.push(node)
	selectedId.value = node.id
	error.value = ''
}
function removeNode(): void {
	if (!selected.value || !canEdit.value || busy.value) return
	const id = selected.value.id
	graph.value.nodes = graph.value.nodes.filter((node) => node.id !== id)
	graph.value.edges = graph.value.edges.filter((edge) => edge.source !== id && edge.target !== id)
	selectedId.value = ''
}
function moved(event: NodeDragEvent): void {
	const node = graph.value.nodes.find((item) => item.id === event.node.id)
	if (node && canEdit.value) node.position = { ...event.node.position }
}
function setConfig(key: string, value: string): void {
	if (!selected.value) return
	if (value === '' && key !== 'value') delete selected.value.config[key]
	else selected.value.config[key] = value
}
function textConfig(key: string): string {
	return String(selected.value?.config[key] ?? '')
}
function message(reason: unknown): string {
	if (reason instanceof ApiError) {
		if (reason.status === 409) {
			conflict.value = true
			return 'Граф изменён в другом окне. Ваши изменения сохранены здесь; обновите версию после проверки.'
		}
		const fieldLabels: Record<string, string> = {
			asset_id: 'CSV файл',
			delimiter: 'разделитель',
			header: 'заголовок',
			field: 'поле',
			operator: 'условие',
			value: 'значение',
			mappings: 'сопоставления',
			source: 'исходное поле',
			target: 'новое имя',
			filename: 'имя результата',
			schema_hints: 'структура',
		}
		const details = Object.entries(reason.fields).flatMap(([field, messages]) =>
			messages.map((item) => {
				const match = /^graph\.nodes\.(\d+)\.(?:config\.)?([^.]+)/.exec(field)
				if (match) {
					const node = graph.value.nodes[Number(match[1])]
					const name = match[2] ?? ''
					const title = `${node ? display(node) : 'Блок'}: ${fieldLabels[name] ?? name}`
					if (name === 'asset_id')
						return `${title}: загрузка файлов появится на следующем этапе.`
					if (/required/i.test(item)) return `${title}: заполните поле.`
					return `${title}: проверьте значение. (${item})`
				}
				return `${field}: ${item}`
			}),
		)
		return details.length ? details.join(' • ') : reason.message
	}
	return 'Не удалось связаться с сервером. Повторите попытку.'
}
async function load(discard = false): Promise<void> {
	if (
		discard &&
		dirty.value &&
		!window.confirm('Отбросить несохранённые изменения и загрузить версию сервера?')
	)
		return
	const sequence = ++loadSequence
	pipeline.value = null
	loading.value = true
	error.value = ''
	notices.value = []
	try {
		const [item, catalog] = await Promise.all([
			request<Pipeline>(url.value),
			request<NodeDefinition[]>('/node-types'),
		])
		if (sequence !== loadSequence) return
		pipeline.value = item
		definitions.value = catalog
		graph.value = structuredClone(item.graph)
		saved.value = JSON.stringify(graph.value)
		selectedId.value = ''
		conflict.value = false
	} catch (reason) {
		if (sequence === loadSequence) error.value = message(reason)
	} finally {
		if (sequence === loadSequence) loading.value = false
	}
}
async function save(): Promise<void> {
	if (!pipeline.value || !canEdit.value || busy.value) return
	busy.value = true
	error.value = ''
	notices.value = []
	try {
		await ensureCsrf()
		const item = await request<Pipeline>(`${url.value}/graph`, {
			method: 'PUT',
			body: JSON.stringify({ revision: pipeline.value.revision, graph: graph.value }),
		})
		pipeline.value = item
		graph.value = structuredClone(item.graph)
		saved.value = JSON.stringify(graph.value)
		conflict.value = false
		notices.value = ['Черновик сохранён.']
	} catch (reason) {
		error.value = message(reason)
	} finally {
		busy.value = false
	}
}
async function validate(): Promise<void> {
	if (!pipeline.value || busy.value) return
	if (dirty.value) {
		error.value = 'Сначала сохраните изменения: проверяется сохранённая версия.'
		return
	}
	busy.value = true
	error.value = ''
	notices.value = []
	try {
		await ensureCsrf()
		const result = await request<{ warnings: string[] }>(`${url.value}/validate`, {
			method: 'POST',
			body: JSON.stringify({ revision: pipeline.value.revision }),
		})
		notices.value = result.warnings.length
			? result.warnings
			: ['Сохранённый граф прошёл проверку.']
	} catch (reason) {
		error.value = message(reason)
	} finally {
		busy.value = false
	}
}
function beforeUnload(event: BeforeUnloadEvent): void {
	if (dirty.value) {
		event.preventDefault()
		event.returnValue = ''
	}
}
function canLeave(): boolean {
	if (busy.value) return false
	return !dirty.value || window.confirm('Есть несохранённые изменения. Покинуть редактор?')
}
function beforeLogout(event: Event): void {
	if (!canLeave()) event.preventDefault()
	else logoutApproved = true
}
function logoutFinished(): void {
	logoutApproved = false
}
onBeforeRouteLeave(() => logoutApproved || canLeave())
onBeforeRouteUpdate((toRoute, fromRoute) => {
	if (toRoute.fullPath !== fromRoute.fullPath && !canLeave()) return false
})
watch([orgId, pipelineId], () => {
	load()
})
onMounted(() => {
	window.addEventListener('beforeunload', beforeUnload)
	window.addEventListener('dataflow-before-logout', beforeLogout)
	window.addEventListener('dataflow-logout-finished', logoutFinished)
	load()
})
onBeforeUnmount(() => {
	window.removeEventListener('beforeunload', beforeUnload)
	window.removeEventListener('dataflow-before-logout', beforeLogout)
	window.removeEventListener('dataflow-logout-finished', logoutFinished)
})
</script>

<template>
	<section class="builder">
		<p v-if="loading">Загрузка редактора…</p>
		<template v-else-if="pipeline">
			<RouterLink
				:to="{ name: 'project', params: { org: orgId, project: pipeline.project_id } }"
				>← К проекту</RouterLink
			>
			<div class="toolbar">
				<div>
					<h1>{{ pipeline.name }}</h1>
					<span class="muted"
						>Версия {{ pipeline.revision }} ·
						{{ dirty ? 'Есть несохранённые изменения' : 'Сохранено' }}</span
					>
				</div>
				<div class="actions">
					<button v-if="canEdit" :disabled="busy || !dirty" @click="save">
						Сохранить</button
					><button :disabled="busy" @click="validate">Проверить</button
					><button disabled title="Исполнение появится на следующем этапе">
						Запустить
					</button>
				</div>
			</div>
			<p v-if="!canEdit" class="muted">
				Только просмотр: ваша роль не позволяет изменять граф.
			</p>
			<p class="muted">Запуск появится после реализации исполнения.</p>
			<div v-if="error" class="notice error" role="alert">
				{{ error }}
				<button v-if="conflict" @click="load(true)">Загрузить версию сервера</button>
			</div>
			<p v-for="notice in notices" :key="notice" class="notice" role="status">{{ notice }}</p>
			<div class="builder-grid">
				<aside class="panel">
					<h2>На схеме</h2>
					<button
						v-for="node in graph.nodes"
						:key="node.id"
						class="catalog-item"
						@click="selectedId = node.id"
					>
						{{ display(node) }} <small>{{ node.id.slice(0, 8) }}</small>
					</button>
					<p v-if="!graph.nodes.length" class="muted">Блоков пока нет.</p>
					<h2>Блоки</h2>
					<p class="muted">Добавьте блок на схему</p>
					<button
						v-for="item in definitions"
						:key="item.type"
						class="catalog-item"
						:disabled="!canEdit || busy"
						@click="addNode(item)"
					>
						{{ labels[item.type] ?? item.label }}<small>{{ item.category }}</small>
					</button>
				</aside>
				<div class="canvas-wrap">
					<VueFlow
						:nodes="flowNodes"
						:edges="flowEdges"
						:nodes-draggable="canEdit && !busy"
						:nodes-connectable="canEdit && !busy"
						:elements-selectable="true"
						:delete-key-code="null"
						fit-view-on-init
						@connect="onConnect"
						@node-click="selectedId = $event.node.id"
						@node-drag-stop="moved"
					/>
					<p v-if="!graph.nodes.length" class="canvas-empty">
						Добавьте первый блок из каталога
					</p>
				</div>
				<aside class="panel settings">
					<template v-if="selected"
						><h2>{{ display(selected) }}</h2>
						<fieldset :disabled="!canEdit || busy">
							<template v-if="selected.type === 'csv.source'"
								><p>Загрузка файлов появится на следующем этапе.</p>
								<label
									>Разделитель<select
										:value="textConfig('delimiter') || ','"
										@change="
											setConfig(
												'delimiter',
												($event.target as HTMLSelectElement).value,
											)
										"
									>
										<option value=",">Запятая</option>
										<option value=";">Точка с запятой</option>
										<option value=":">Двоеточие</option>
										<option value="|">Вертикальная черта</option>
									</select></label
								><label class="inline"
									><input
										type="checkbox"
										:checked="selected.config.header !== false"
										@change="
											selected.config.header = (
												$event.target as HTMLInputElement
											).checked
										"
									/>
									Первая строка — заголовок</label
								>
								<p>Поля можно указать вручную.</p>
								<button
									v-if="!selected.schema_hints"
									type="button"
									@click="selected.schema_hints = []"
								>
									Задать структуру</button
								><template v-else
									><div
										v-for="(field, index) in selected.schema_hints"
										:key="index"
										class="mapping"
									>
										<label
											>Имя поля<input
												v-model="field.name"
												maxlength="128" /></label
										><label
											>Тип<select v-model="field.type">
												<option
													v-for="type in [
														'string',
														'integer',
														'float',
														'decimal',
														'boolean',
														'date',
														'datetime',
														'json',
														'unknown',
													]"
													:key="type"
													:value="type"
												>
													{{ type }}
												</option>
											</select></label
										><label class="inline"
											><input v-model="field.nullable" type="checkbox" />
											Допускает пустое значение</label
										><button
											type="button"
											class="secondary"
											@click="selected.schema_hints.splice(index, 1)"
										>
											Удалить поле
										</button>
									</div>
									<button
										type="button"
										@click="
											selected.schema_hints.push({
												name: '',
												type: 'string',
												nullable: true,
											})
										"
									>
										Добавить поле</button
									><button
										type="button"
										class="secondary"
										@click="delete selected.schema_hints"
									>
										Структура неизвестна
									</button></template
								></template
							>
							<template v-if="selected.type === 'transform.filter'"
								><p v-if="!selectedSchema" class="muted">
									Структура данных пока неизвестна. Введите имя поля вручную.
								</p>
								<label
									>Поле<select
										v-if="selectedSchema"
										:value="textConfig('field')"
										@change="
											setConfig(
												'field',
												($event.target as HTMLSelectElement).value,
											)
										"
									>
										<option value="">Выберите поле</option>
										<option
											v-for="field in selectedSchema"
											:key="field.name"
											:value="field.name"
										>
											{{ field.name }}
										</option></select
									><input
										v-else
										:value="textConfig('field')"
										@input="
											setConfig(
												'field',
												($event.target as HTMLInputElement).value,
											)
										" /></label
								><label
									>Условие<select
										:value="textConfig('operator')"
										@change="
											setConfig(
												'operator',
												($event.target as HTMLSelectElement).value,
											)
										"
									>
										<option value="">Выберите условие</option>
										<option value="equals">Равно</option>
										<option value="not_equals">Не равно</option>
										<option value="is_null">Пусто</option>
										<option value="is_not_null">Не пусто</option>
										<option value="greater_than">Больше</option>
										<option value="less_than">Меньше</option>
									</select></label
								><label
									v-if="
										!['is_null', 'is_not_null'].includes(textConfig('operator'))
									"
									>Значение<input
										:value="textConfig('value')"
										@input="
											setConfig(
												'value',
												($event.target as HTMLInputElement).value,
											)
										"
								/></label>
								<p class="muted">
									В первом срезе CSV значения являются строками; числовое
									сравнение требует приведения типов.
								</p></template
							>
							<template v-if="selected.type === 'transform.map'"
								><p v-if="!selectedSchema" class="muted">
									Структура данных пока неизвестна. Введите имена полей вручную.
								</p>
								<div
									v-for="(mapping, index) in mappings"
									:key="index"
									class="mapping"
								>
									<label
										>Исходное поле<select
											v-if="selectedSchema"
											v-model="mapping.source"
										>
											<option value="">Выберите поле</option>
											<option
												v-for="field in selectedSchema"
												:key="field.name"
												:value="field.name"
											>
												{{ field.name }}
											</option></select
										><input v-else v-model="mapping.source" /></label
									><label>Новое имя<input v-model="mapping.target" /></label
									><button
										type="button"
										class="secondary"
										@click="removeMapping(index)"
									>
										Удалить
									</button>
								</div>
								<button type="button" @click="addMapping()">
									Добавить сопоставление
								</button>
								<div v-if="outputSchema">
									<h3>Выходные поля</h3>
									<p v-for="field in outputSchema" :key="field.name">
										{{ field.name }} · {{ field.type }}
									</p>
								</div></template
							>
							<template v-if="selected.type === 'csv.destination'"
								><label
									>Имя результата<input
										:value="textConfig('filename')"
										placeholder="result.csv"
										@input="
											setConfig(
												'filename',
												($event.target as HTMLInputElement).value,
											)
										" /></label
							></template>
							<button type="button" class="danger" @click="removeNode">
								Удалить блок
							</button>
						</fieldset></template
					>
					<p v-else class="muted">Выберите блок на схеме, чтобы настроить его.</p>
				</aside>
			</div>
			<section class="panel connections">
				<h2>Связи</h2>
				<p class="muted">Для соединения без перетаскивания выберите выход и вход.</p>
				<div class="connection-form">
					<label
						>Выход<select v-model="from" :disabled="!canEdit || busy">
							<option value="">Выберите блок</option>
							<option
								v-for="node in graph.nodes.filter(
									(n) => definition(n.type)?.outputs.records,
								)"
								:key="node.id"
								:value="node.id"
							>
								{{ display(node) }} · {{ node.id.slice(0, 8) }}
							</option>
						</select></label
					><label
						>Вход<select v-model="to" :disabled="!canEdit || busy">
							<option value="">Выберите блок</option>
							<option
								v-for="node in graph.nodes.filter(
									(n) => definition(n.type)?.inputs.records,
								)"
								:key="node.id"
								:value="node.id"
							>
								{{ display(node) }} · {{ node.id.slice(0, 8) }}
							</option>
						</select></label
					><button :disabled="!canEdit || busy" @click="connect(from, to)">
						Соединить
					</button>
				</div>
				<ul>
					<li v-for="edge in graph.edges" :key="edge.id">
						{{ display(graph.nodes.find((n) => n.id === edge.source)!) }} →
						{{ display(graph.nodes.find((n) => n.id === edge.target)!) }}
						<button
							v-if="canEdit"
							class="secondary"
							:disabled="busy"
							@click="graph.edges = graph.edges.filter((e) => e.id !== edge.id)"
						>
							Удалить связь
						</button>
					</li>
				</ul>
			</section>
		</template>
		<div v-else class="panel">
			<p class="error">{{ error || 'Поток недоступен.' }}</p>
			<button @click="load()">Повторить</button>
		</div>
	</section>
</template>
<style scoped>
.builder {
	max-width: 1300px;
	margin: auto;
}
.toolbar,
.actions,
.connection-form {
	display: flex;
	gap: 12px;
	align-items: center;
	flex-wrap: wrap;
	justify-content: space-between;
}
h1 {
	margin-bottom: 4px;
}
h2 {
	font-size: 1.1rem;
	margin-top: 0;
}
h3 {
	font-size: 1rem;
}
.builder-grid {
	display: grid;
	grid-template-columns: 180px minmax(300px, 1fr) 280px;
	gap: 12px;
	align-items: stretch;
	margin: 18px 0;
}
.panel {
	background: white;
	border: 1px solid #dbe4e9;
	border-radius: 10px;
	padding: 16px;
	min-width: 0;
}
.catalog-item {
	display: grid;
	width: 100%;
	text-align: left;
	background: #e8f3f1;
	color: #164e49;
	margin: 8px 0;
	gap: 4px;
}
small {
	font-weight: 400;
	color: #526c6a;
}
.canvas-wrap {
	position: relative;
	height: 560px;
	background: #f1f5f9;
	border: 1px solid #dbe4e9;
	border-radius: 10px;
	overflow: hidden;
}
.canvas-empty {
	position: absolute;
	top: 45%;
	left: 10%;
	right: 10%;
	text-align: center;
	color: #64748b;
	pointer-events: none;
}
.settings {
	max-height: 560px;
	overflow: auto;
}
fieldset {
	display: grid;
	gap: 12px;
	border: 0;
	padding: 0;
	margin: 0;
	min-width: 0;
}
fieldset label,
.mapping {
	display: grid;
	gap: 6px;
}
select {
	width: 100%;
	padding: 9px;
	border: 1px solid #94a3b8;
	border-radius: 6px;
	background: white;
	font: inherit;
}
.inline {
	display: flex;
	align-items: center;
	font-weight: 400;
}
.mapping {
	border-bottom: 1px solid #e2e8f0;
	padding-bottom: 12px;
}
.secondary {
	background: #e2e8f0;
	color: #334155;
}
.danger {
	background: #9f3434;
}
.notice {
	padding: 12px;
	background: #f1f5f9;
	border-radius: 6px;
	overflow-wrap: anywhere;
}
.error {
	background: #fff1f2;
}
.connections {
	margin-bottom: 30px;
}
.connections ul {
	display: grid;
	gap: 8px;
	padding-left: 20px;
}
.connection-form {
	justify-content: flex-start;
	align-items: end;
}
.connection-form label {
	min-width: 160px;
}
button:focus-visible,
input:focus-visible,
select:focus-visible {
	outline: 3px solid #38bdf8;
	outline-offset: 2px;
}
@media (max-width: 900px) {
	.builder-grid {
		grid-template-columns: 1fr;
	}
	.canvas-wrap {
		height: 430px;
	}
	.settings {
		max-height: none;
	}
}
</style>
