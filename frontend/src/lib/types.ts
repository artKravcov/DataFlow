export type Role = 'owner' | 'editor' | 'viewer'
export interface Organization {
	id: number
	name: string
	role: Role
}
export interface User {
	id: number
	name: string
	email: string
	organizations: Organization[]
}
export interface Project {
	id: number
	name: string
	description: string | null
	created_at: string
	updated_at: string
}
export interface ProjectInput {
	name: string
	description: string | null
}
export interface Pagination {
	current_page: number
	last_page: number
	per_page: number
	total: number
}
