<script setup lang="ts">
import { reactive, watch } from 'vue'
import type { FieldErrors } from '@/lib/api'
import type { ProjectInput } from '@/lib/types'
const props = defineProps<{
	modelValue: ProjectInput
	fields: FieldErrors
	submitting: boolean
	submitLabel: string
}>()
const emit = defineEmits<{ submit: [value: ProjectInput] }>()
const form = reactive<ProjectInput>({ ...props.modelValue })
watch(
	() => props.modelValue,
	(value) => Object.assign(form, value),
)

function submit(): void {
	emit('submit', {
		name: form.name.trim(),
		description: form.description?.trim() || null,
	})
}
</script>
<template>
	<form class="stack" @submit.prevent="submit">
		<label
			>Name<input v-model="form.name" required maxlength="255" /><span
				v-if="fields.name"
				class="field-error"
				>{{ fields.name[0] }}</span
			></label
		><label
			>Description <span class="muted">(optional)</span
			><textarea v-model="form.description" maxlength="5000"></textarea
			><span v-if="fields.description" class="field-error">{{
				fields.description[0]
			}}</span></label
		><button :disabled="submitting">
			{{ submitting ? 'Saving…' : submitLabel }}
		</button>
	</form>
</template>
