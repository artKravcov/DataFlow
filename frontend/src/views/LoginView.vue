<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
const email = ref('')
const password = ref('')
const submitting = ref(false)
const error = ref('')
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

async function submit(): Promise<void> {
	error.value = ''
	submitting.value = true
	try {
		await auth.login(email.value, password.value)
		const returnTo =
			typeof route.query.returnTo === 'string' && route.query.returnTo.startsWith('/')
				? route.query.returnTo
				: '/organizations'
		await router.push(returnTo)
	} catch (reason) {
		error.value =
			reason instanceof ApiError
				? (reason.fields.email?.[0] ?? reason.message)
				: 'Не удалось войти. Повторите попытку.'
	} finally {
		submitting.value = false
	}
}
</script>
<template>
	<main class="page auth">
		<section class="card">
			<h1>Вход в DataFlow</h1>
			<p class="muted">Войдите, чтобы работать со своими организациями и проектами.</p>
			<form class="stack" @submit.prevent="submit">
				<label
					>Email<input
						v-model="email"
						required
						type="email"
						autocomplete="email" /></label
				><label
					>Пароль<input
						v-model="password"
						required
						type="password"
						autocomplete="current-password"
				/></label>
				<p v-if="error" class="error" role="alert">{{ error }}</p>
				<button :disabled="submitting">
					{{ submitting ? 'Вход…' : 'Войти' }}
				</button>
			</form>
			<p>Нет аккаунта? <RouterLink to="/register">Зарегистрироваться</RouterLink></p>
		</section>
	</main>
</template>
<style scoped>
.auth {
	padding-top: 10vh;
	max-width: 440px;
}
</style>
