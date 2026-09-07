<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { PreviewQueue } from '../services/previewQueue.ts'

const albumPreviewQueue = new PreviewQueue(() => 4)
const props = defineProps<{ src: string; alt?: string }>()
const emit = defineEmits<{ error: [] }>()
const host = ref<HTMLElement | null>(null)
const source = ref('')
const ready = ref(false)
let observer: IntersectionObserver | undefined
let controller: AbortController | undefined

function release() {
	controller?.abort()
	controller = undefined
	if (source.value.startsWith('blob:')) URL.revokeObjectURL(source.value)
	source.value = ''
	ready.value = false
}

async function load() {
	if (source.value || controller) return
	controller = new AbortController()
	try {
		source.value = await albumPreviewQueue.enqueue(props.src, controller)
	} catch (error) {
		if (!(error instanceof DOMException && error.name === 'AbortError')) emit('error')
	}
}

watch(() => props.src, () => {
	release()
	if (!observer) void load()
})
onMounted(() => {
	if (typeof IntersectionObserver === 'undefined') return void load()
	observer = new IntersectionObserver(entries => {
		if (!entries.some(entry => entry.isIntersecting)) return
		observer?.disconnect()
		observer = undefined
		void load()
	}, { rootMargin: '480px' })
	if (host.value) observer.observe(host.value)
})
onBeforeUnmount(() => {
	observer?.disconnect()
	release()
})
</script>

<template>
	<span ref="host" class="album-cover" :class="{ 'album-cover--ready': ready }">
		<img v-if="source"
			:src="source"
			:alt="alt ?? ''"
			loading="lazy"
			decoding="async"
			@load="ready = true"
			@error="emit('error')">
	</span>
</template>

<style scoped>
.album-cover { display: block; width: 100%; height: 100%; background: color-mix(in srgb, currentColor 7%, transparent); }

.album-cover img { display: block; width: 100%; height: 100%; object-fit: cover; opacity: 0; transition: opacity 180ms ease; }

.album-cover--ready img { opacity: 1; }
@media (prefers-reduced-motion: reduce) { .album-cover img { transition: none; } }
</style>
