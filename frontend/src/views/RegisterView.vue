<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { ApiError, type FieldErrors } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const submitting = ref(false)
const error = ref('')
const fields = ref<FieldErrors>({})
const auth = useAuthStore()
const router = useRouter()

async function submit(): Promise<void> {
	error.value = ''
	fields.value = {}
	submitting.value = true
	try {
		await auth.register(name.value, email.value, password.value, passwordConfirmation.value)
		await router.push({ name: 'organizations' })
	} catch (reason) {
		if (reason instanceof ApiError) {
			error.value = reason.message
			fields.value = reason.fields
		} else error.value = 'Registration failed. Please try again.'
	} finally {
		submitting.value = false
	}
}
</script>
<template>
	<main class="page auth">
		<section class="card">
			<h1>Create an account</h1>
			<p class="muted">A personal organization will be created automatically.</p>
			<form class="stack" @submit.prevent="submit">
				<label
					>Name<input v-model="name" required autocomplete="name" /><span
						v-if="fields.name"
						class="field-error"
						>{{ fields.name[0] }}</span
					></label
				><label
					>Email<input v-model="email" required type="email" autocomplete="email" /><span
						v-if="fields.email"
						class="field-error"
						>{{ fields.email[0] }}</span
					></label
				><label
					>Password<input
						v-model="password"
						required
						type="password"
						minlength="12"
						autocomplete="new-password"
					/><span v-if="fields.password" class="field-error">{{
						fields.password[0]
					}}</span></label
				><label
					>Confirm password<input
						v-model="passwordConfirmation"
						required
						type="password"
						minlength="12"
						autocomplete="new-password"
				/></label>
				<p v-if="error" class="error" role="alert">{{ error }}</p>
				<button :disabled="submitting">
					{{ submitting ? 'Creating…' : 'Register' }}
				</button>
			</form>
			<p>Already have an account? <RouterLink to="/login">Sign in</RouterLink></p>
		</section>
	</main>
</template>
<style scoped>
.auth {
	padding-top: 7vh;
	max-width: 440px;
}
</style>
