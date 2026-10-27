<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate, RouterLink, useRoute } from 'vue-router'
import { VueFlow, type Connection, type NodeDragEvent } from '@vue-flow/core'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import { ApiError, ensureCsrf, request } from '@/lib/api'
import ExecutionPanel from '@/components/ExecutionPanel.vue'
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
type Asset = { id: number; name: string; size_bytes: number }
const assets = ref<Asset[]>([])
const assetPage = ref(1)
const assetHasMore = ref(false)
const assetUrl = computed(() => `/organizations/${orgId.value}/assets`)
async function loadAssets(page = 1): Promise<void> {
	const items = await request<Asset[]>(`${assetUrl.value}?page=${page}`)
	assets.value = items
	assetPage.value = page
	assetHasMore.value = items.length === 25
}
async function uploadAsset(event: Event): Promise<void> {
	const input = event.target as HTMLInputElement
	const file = input.files?.[0]
	const node = selected.value
	if (!file || !node || busy.value || !canEdit.value) return
	busy.value = true
	error.value = ''
	try {
		await ensureCsrf()
		const form = new FormData()
		form.append('file', file)
		const asset = await request<Asset>(assetUrl.value, { method: 'POST', body: form })
		await loadAssets(1)
		if (!assets.value.some((item) => item.id === asset.id)) assets.value.unshift(asset)
		node.config.asset_id = String(asset.id)
	} catch (reason) {
		error.value =
			reason instanceof ApiError && reason.status === 413
				? 'File is too large: maximum 10 MiB.'
				: message(reason)
	} finally {
		input.value = ''
		busy.value = false
	}
}
async function changeAssetPage(page: number): Promise<void> {
	busy.value = true
	try {
		await loadAssets(page)
	} catch (reason) {
		error.value = message(reason)
	} finally {
		busy.value = false
	}
}
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
	'csv.source': 'CSV source',
	'transform.filter': 'Filter',
	'transform.map': 'Map / Rename',
	'csv.destination': 'CSV destination',
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
	if (!a || !b) return 'Choose an output and an input.'
	const output = definition(a.type)?.outputs.records
	const input = definition(b.type)?.inputs.records
	if (!output || !input || output !== input) return 'These node ports are incompatible.'
	if (graph.value.edges.some((edge) => edge.target === target))
		return 'This input is already connected.'
	const stack = [target]
	const visited = new Set<string>()
	while (stack.length) {
		const id = stack.pop()!
		if (id === source) return 'This connection creates a cycle.'
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
		error.value = 'The limit of 100 connections has been reached.'
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
		error.value = 'The limit of 50 nodes has been reached.'
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
			return 'The graph changed in another window. Your changes are kept here; refresh the version after reviewing.'
		}
		const fieldLabels: Record<string, string> = {
			asset_id: 'CSV file',
			delimiter: 'delimiter',
			header: 'header',
			field: 'field',
			operator: 'condition',
			value: 'value',
			mappings: 'mappings',
			source: 'source field',
			target: 'new name',
			filename: 'result name',
			schema_hints: 'schema',
		}
		const details = Object.entries(reason.fields).flatMap(([field, messages]) =>
			messages.map((item) => {
				const match = /^graph\.nodes\.(\d+)\.(?:config\.)?([^.]+)/.exec(field)
				if (match) {
					const node = graph.value.nodes[Number(match[1])]
					const name = match[2] ?? ''
					const title = `${node ? display(node) : 'Node'}: ${fieldLabels[name] ?? name}`
					if (name === 'asset_id')
						return `${title}: choose an available file from this organization.`
					if (/required/i.test(item)) return `${title}: fill in this field.`
					return `${title}: check this value. (${item})`
				}
				return `${field}: ${item}`
			}),
		)
		return details.length ? details.join(' • ') : reason.message
	}
	return 'Could not reach the server. Please try again.'
}
async function load(discard = false): Promise<void> {
	if (
		discard &&
		dirty.value &&
		!window.confirm('Discard unsaved changes and load the server version?')
	)
		return
	const sequence = ++loadSequence
	pipeline.value = null
	loading.value = true
	error.value = ''
	notices.value = []
	try {
		const [item, catalog, assetItems] = await Promise.all([
			request<Pipeline>(url.value),
			request<NodeDefinition[]>('/node-types'),
			request<Asset[]>(`${assetUrl.value}?page=1`),
		])
		if (sequence !== loadSequence) return
		pipeline.value = item
		definitions.value = catalog
		assets.value = assetItems
		assetPage.value = 1
		assetHasMore.value = assetItems.length === 25
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
		notices.value = ['Draft saved.']
	} catch (reason) {
		error.value = message(reason)
	} finally {
		busy.value = false
	}
}
async function validate(): Promise<void> {
	if (!pipeline.value || busy.value) return
	if (dirty.value) {
		error.value = 'Save changes first: validation checks the saved version.'
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
			: ['The saved graph passed validation.']
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
	return !dirty.value || window.confirm('There are unsaved changes. Leave the editor?')
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
		<p v-if="loading">Loading editor…</p>
		<template v-else-if="pipeline">
			<RouterLink
				:to="{ name: 'project', params: { org: orgId, project: pipeline.project_id } }"
				>← Back to project</RouterLink
			>
			<div class="toolbar">
				<div>
					<h1>{{ pipeline.name }}</h1>
					<span class="muted"
						>Version {{ pipeline.revision }} ·
						{{ dirty ? 'Unsaved changes' : 'Saved' }}</span
					>
				</div>
				<div class="actions">
					<button v-if="canEdit" :disabled="busy || !dirty" @click="save">Save</button
					><button :disabled="busy" @click="validate">Validate</button>
				</div>
			</div>
			<p v-if="!canEdit" class="muted">
				Read-only: your role does not allow editing the graph.
			</p>
			<ExecutionPanel
				:org-id="orgId"
				:pipeline-id="pipelineId"
				:user-id="auth.user?.id ?? 0"
				:revision="pipeline.revision"
				:dirty="dirty"
				:can-edit="canEdit"
			/>
			<div v-if="error" class="notice error" role="alert">
				{{ error }}
				<button v-if="conflict" @click="load(true)">Load server version</button>
			</div>
			<p v-for="notice in notices" :key="notice" class="notice" role="status">{{ notice }}</p>
			<div class="builder-grid">
				<aside class="panel">
					<h2>On canvas</h2>
					<button
						v-for="node in graph.nodes"
						:key="node.id"
						class="catalog-item"
						@click="selectedId = node.id"
					>
						{{ display(node) }} <small>{{ node.id.slice(0, 8) }}</small>
					</button>
					<p v-if="!graph.nodes.length" class="muted">No nodes on the canvas yet.</p>
					<h2>Nodes</h2>
					<p class="muted">Add a node to the canvas</p>
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
						Add the first node from the catalog
					</p>
				</div>
				<aside class="panel settings">
					<template v-if="selected"
						><h2>{{ display(selected) }}</h2>
						<fieldset :disabled="!canEdit || busy">
							<template v-if="selected.type === 'csv.source'"
								><label
									>CSV file<select
										:value="textConfig('asset_id')"
										@change="
											setConfig(
												'asset_id',
												($event.target as HTMLSelectElement).value,
											)
										"
									>
										<option value="">Choose a file</option>
										<option
											v-if="
												textConfig('asset_id') &&
												!assets.some(
													(item) =>
														String(item.id) === textConfig('asset_id'),
												)
											"
											:value="textConfig('asset_id')"
										>
											Selected file · {{ textConfig('asset_id') }}
										</option>
										<option
											v-for="asset in assets"
											:key="asset.id"
											:value="String(asset.id)"
										>
											{{ asset.name }} · {{ asset.size_bytes }} bytes
										</option>
									</select></label
								>
								<div>
									<button
										type="button"
										:disabled="assetPage <= 1"
										@click="changeAssetPage(assetPage - 1)"
									>
										Previous
									</button>
									Page {{ assetPage }}
									<button
										type="button"
										:disabled="!assetHasMore"
										@click="changeAssetPage(assetPage + 1)"
									>
										Next
									</button>
								</div>
								<label
									>Upload CSV (up to 10 MiB)<input
										type="file"
										accept=".csv,text/csv"
										@change="uploadAsset"
								/></label>
								<label
									>Delimiter<select
										:value="textConfig('delimiter') || ','"
										@change="
											setConfig(
												'delimiter',
												($event.target as HTMLSelectElement).value,
											)
										"
									>
										<option value=",">Comma</option>
										<option value=";">Semicolon</option>
										<option value=":">Colon</option>
										<option value="|">Pipe</option>
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
									First row is a header</label
								>
								<p>Fields can be entered manually.</p>
								<button
									v-if="!selected.schema_hints"
									type="button"
									@click="selected.schema_hints = []"
								>
									Define schema</button
								><template v-else
									><div
										v-for="(field, index) in selected.schema_hints"
										:key="index"
										class="mapping"
									>
										<label
											>Field name<input
												v-model="field.name"
												maxlength="128" /></label
										><label
											>Type<select v-model="field.type">
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
											Allows empty values</label
										><button
											type="button"
											class="secondary"
											@click="selected.schema_hints.splice(index, 1)"
										>
											Remove field
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
										Add field</button
									><button
										type="button"
										class="secondary"
										@click="delete selected.schema_hints"
									>
										Schema unknown
									</button></template
								></template
							>
							<template v-if="selected.type === 'transform.filter'"
								><p v-if="!selectedSchema" class="muted">
									The data schema is not known yet. Enter the field name manually.
								</p>
								<label
									>Field<select
										v-if="selectedSchema"
										:value="textConfig('field')"
										@change="
											setConfig(
												'field',
												($event.target as HTMLSelectElement).value,
											)
										"
									>
										<option value="">Choose a field</option>
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
									>Condition<select
										:value="textConfig('operator')"
										@change="
											setConfig(
												'operator',
												($event.target as HTMLSelectElement).value,
											)
										"
									>
										<option value="">Choose a condition</option>
										<option value="equals">Equals</option>
										<option value="not_equals">Does not equal</option>
										<option value="is_null">Is empty</option>
										<option value="is_not_null">Is not empty</option>
										<option value="greater_than">Greater than</option>
										<option value="less_than">Less than</option>
									</select></label
								><label
									v-if="
										!['is_null', 'is_not_null'].includes(textConfig('operator'))
									"
									>Value<input
										:value="textConfig('value')"
										@input="
											setConfig(
												'value',
												($event.target as HTMLInputElement).value,
											)
										"
								/></label>
								<p class="muted">
									In this first version, CSV values are strings; numeric
									comparison requires type conversion.
								</p></template
							>
							<template v-if="selected.type === 'transform.map'"
								><p v-if="!selectedSchema" class="muted">
									The data schema is not known yet. Enter field names manually.
								</p>
								<div
									v-for="(mapping, index) in mappings"
									:key="index"
									class="mapping"
								>
									<label
										>Source field<select
											v-if="selectedSchema"
											v-model="mapping.source"
										>
											<option value="">Choose a field</option>
											<option
												v-for="field in selectedSchema"
												:key="field.name"
												:value="field.name"
											>
												{{ field.name }}
											</option></select
										><input v-else v-model="mapping.source" /></label
									><label>New name<input v-model="mapping.target" /></label
									><button
										type="button"
										class="secondary"
										@click="removeMapping(index)"
									>
										Remove
									</button>
								</div>
								<button type="button" @click="addMapping()">Add mapping</button>
								<div v-if="outputSchema">
									<h3>Output fields</h3>
									<p v-for="field in outputSchema" :key="field.name">
										{{ field.name }} · {{ field.type }}
									</p>
								</div></template
							>
							<template v-if="selected.type === 'csv.destination'"
								><label
									>Result filename<input
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
								Remove node
							</button>
						</fieldset></template
					>
					<p v-else class="muted">Select a node on the canvas to configure it.</p>
				</aside>
			</div>
			<section class="panel connections">
				<h2>Connections</h2>
				<p class="muted">To connect without dragging, choose an output and an input.</p>
				<div class="connection-form">
					<label
						>Output<select v-model="from" :disabled="!canEdit || busy">
							<option value="">Choose a node</option>
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
						>Input<select v-model="to" :disabled="!canEdit || busy">
							<option value="">Choose a node</option>
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
						Connect
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
							Remove connection
						</button>
					</li>
				</ul>
			</section>
		</template>
		<div v-else class="panel">
			<p class="error">{{ error || 'Pipeline is unavailable.' }}</p>
			<button @click="load()">Retry</button>
		</div>
	</section>
</template>
<style scoped>
.builder {
	max-width: 1440px;
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
	grid-template-columns: 180px minmax(400px, 1fr) 370px;
	gap: 16px;
	align-items: stretch;
	margin: 18px 0;
}
.panel {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: 12px;
	padding: 14px;
	min-width: 0;
}
.catalog-item {
	display: grid;
	width: 100%;
	text-align: left;
	background: #1a3152;
	color: var(--text);
	margin: 4px 0;
	gap: 4px;
	padding: 8px 10px;
	font-size: 0.88rem;
}
small {
	font-weight: 400;
	color: var(--muted);
	font-size: 0.78rem;
}
.canvas-wrap {
	position: relative;
	height: 590px;
	background: #0b1424;
	border: 1px solid var(--border);
	border-radius: 12px;
	overflow: hidden;
}
.canvas-empty {
	position: absolute;
	top: 45%;
	left: 10%;
	right: 10%;
	text-align: center;
	color: var(--muted);
	pointer-events: none;
}
.settings {
	max-height: 590px;
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
}
.inline {
	display: flex;
	align-items: center;
	font-weight: 400;
}
.mapping {
	border-bottom: 1px solid var(--border);
	padding-bottom: 12px;
}
.secondary {
	background: #243652;
	color: var(--text);
}
.danger {
	background: #be3854;
}
.notice {
	padding: 12px;
	background: #182943;
	border-radius: 6px;
	overflow-wrap: anywhere;
}
.error {
	background: #3a1d2a;
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
	outline: 3px solid var(--link);
	outline-offset: 2px;
}
@media (max-width: 900px) {
	.builder-grid {
		grid-template-columns: 1fr;
	}
	.canvas-wrap {
		height: 460px;
	}
	.settings {
		max-height: none;
	}
}
:deep(.vue-flow__node) {
	background: #162641;
	color: var(--text);
	border: 1px solid #5276a6;
	border-radius: 9px;
	box-shadow: 0 8px 22px rgb(0 0 0 / 0.28);
	min-width: 142px;
	padding: 11px 14px;
	font-size: 0.9rem;
}
:deep(.vue-flow__node.selected) {
	box-shadow:
		0 0 0 2px var(--primary),
		0 8px 22px rgb(0 0 0 / 0.28);
}
:deep(.vue-flow__edge-path) {
	stroke: #7dd3fc;
	stroke-width: 2;
}
</style>
