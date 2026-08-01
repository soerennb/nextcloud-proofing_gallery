<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { queuedPreview } from '../services/previewQueue.ts'

const props = withDefaults(defineProps<{
	src: string
	alt?: string
	priority?: boolean
	direct?: boolean
}>(), {
	alt: '',
	priority: false,
	direct: false,
})
const emit = defineEmits<{ load: [event: Event]; error: [] }>()
const loadedSource = ref('')
const ready = ref(false)
let controller: AbortController | undefined

function release() {
	controller?.abort()
	controller = undefined
	if (loadedSource.value.startsWith('blob:')) URL.revokeObjectURL(loadedSource.value)
	loadedSource.value = ''
	ready.value = false
}

watch(() => props.src, async source => {
	release()
	if (props.priority || props.direct) {
		// The server-rendered preload and the first image now share the exact URL,
		// allowing the browser to reuse the response for LCP without a blob fetch.
		loadedSource.value = source
		return
	}
	controller = new AbortController()
	try {
		loadedSource.value = await queuedPreview(source, controller, props.priority)
	} catch (error) {
		if (!(error instanceof DOMException && error.name === 'AbortError')) emit('error')
	}
}, { immediate: true })

onBeforeUnmount(release)
</script>

<template>
	<span class="progressive-image" :class="{ 'progressive-image--ready': ready }">
		<img
			v-if="loadedSource"
			:src="loadedSource"
			:alt="alt"
			:fetchpriority="priority ? 'high' : undefined"
			:loading="priority ? 'eager' : 'lazy'"
			decoding="async"
			@load="ready = true; emit('load', $event)"
			@error="emit('error')">
	</span>
</template>

<style scoped>
.progressive-image {
	display: block;
	width: 100%;
	height: 100%;
	background: color-mix(in srgb, currentColor 7%, transparent);
}

.progressive-image img {
	display: block;
	width: 100%;
	height: 100%;
	opacity: 0;
	object-fit: inherit;
	transition: opacity 160ms ease;
}

.progressive-image--ready img { opacity: 1; }

@media (prefers-reduced-motion: reduce) {
	.progressive-image img { transition: none; }
}
</style>
