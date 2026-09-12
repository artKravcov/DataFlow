import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { ApiError, ensureCsrf, request } from '@/lib/api'
import type { Organization, User } from '@/lib/types'

export const useAuthStore = defineStore('auth', () => {
	const user = ref<User | null>(null)
	const initialized = ref(false)
	const isAuthenticated = computed(() => user.value !== null)

	function organizationById(id: number): Organization | undefined {
		return user.value?.organizations.find((organization) => organization.id === id)
	}

	async function loadMe(): Promise<void> {
		try {
			user.value = await request<User>('/me')
		} catch (error) {
			if (!(error instanceof ApiError) || error.status !== 401) throw error
			user.value = null
		} finally {
			initialized.value = true
		}
	}

	async function login(email: string, password: string): Promise<void> {
		await ensureCsrf()
		await request('/auth/login', {
			method: 'POST',
			body: JSON.stringify({ email, password }),
		})
		await loadMe()
	}

	async function register(
		name: string,
		email: string,
		password: string,
		passwordConfirmation: string,
	): Promise<void> {
		await ensureCsrf()
		await request('/auth/register', {
			method: 'POST',
			body: JSON.stringify({
				name,
				email,
				password,
				password_confirmation: passwordConfirmation,
			}),
		})
		await loadMe()
	}

	async function logout(): Promise<void> {
		await ensureCsrf()
		await request('/auth/logout', { method: 'POST' })
		user.value = null
	}
	return {
		user,
		initialized,
		isAuthenticated,
		organizationById,
		loadMe,
		login,
		register,
		logout,
	}
})
