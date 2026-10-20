export type FieldErrors = Record<string, string[]>

export class ApiError extends Error {
	constructor(
		public readonly status: number,
		public readonly code: string,
		message: string,
		public readonly fields: FieldErrors = {},
	) {
		super(message)
	}
}

type Envelope<T> = { data: T }
type ErrorEnvelope = {
	error?: { code?: string; message?: string; fields?: FieldErrors }
}

function csrfToken(): string | undefined {
	const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='))
	return cookie ? decodeURIComponent(cookie.substring('XSRF-TOKEN='.length)) : undefined
}

export async function ensureCsrf(): Promise<void> {
	const response = await fetch('/sanctum/csrf-cookie', {
		credentials: 'same-origin',
		headers: { Accept: 'application/json' },
	})
	if (!response.ok) throw await toApiError(response)
}

export async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
	const method = (init.method ?? 'GET').toUpperCase()
	const headers = new Headers(init.headers)
	headers.set('Accept', 'application/json')
	if (init.body !== undefined && !(init.body instanceof FormData))
		headers.set('Content-Type', 'application/json')
	if (!['GET', 'HEAD'].includes(method)) {
		const token = csrfToken()
		if (token) headers.set('X-XSRF-TOKEN', token)
	}
	const response = await fetch(`/api/v1${path}`, {
		...init,
		method,
		headers,
		credentials: 'same-origin',
	})
	if (!response.ok) throw await toApiError(response)
	return ((await response.json()) as Envelope<T>).data
}

async function toApiError(response: Response): Promise<ApiError> {
	const payload = (await response.json().catch(() => ({}))) as ErrorEnvelope
	return new ApiError(
		response.status,
		payload.error?.code ?? 'REQUEST_FAILED',
		payload.error?.message ?? 'Не удалось выполнить запрос.',
		payload.error?.fields ?? {},
	)
}
