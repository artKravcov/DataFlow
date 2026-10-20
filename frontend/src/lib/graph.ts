export interface SchemaField {
	name: string
	type:
		| 'string'
		| 'integer'
		| 'float'
		| 'decimal'
		| 'boolean'
		| 'date'
		| 'datetime'
		| 'json'
		| 'unknown'
	nullable: boolean
	metadata?: { label?: string }
}
export interface GraphNode {
	id: string
	type: string
	type_version: number
	position: { x: number; y: number }
	config: Record<string, unknown>
	schema_hints?: SchemaField[]
}
export interface GraphEdge {
	id: string
	source: string
	source_port: string
	target: string
	target_port: string
}
export interface Graph {
	schema_version: 1
	nodes: GraphNode[]
	edges: GraphEdge[]
}
export interface Pipeline {
	id: number
	project_id: number
	name: string
	revision: number
	graph: Graph
}
export interface NodeDefinition {
	type: string
	type_version: number
	label: string
	category: 'source' | 'transform' | 'destination'
	inputs: Record<string, string>
	outputs: Record<string, string>
	config_schema: Record<string, unknown>
	input_contract: Record<string, string>
	output_contract: Record<string, string>
	schema_rule: string
	runtime_available: boolean
}
