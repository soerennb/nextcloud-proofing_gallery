import { computed, nextTick, ref } from 'vue'
import type { Ref } from 'vue'

export function useLightboxFeedbackModal(options: {
	open: Ref<boolean>
	keyboardPositioning: Ref<boolean>
	selectedCommentId: Ref<number | null>
	shell: Ref<HTMLElement | null>
}) {
	const active = ref(false)
	const presented = ref(false)
	const focusReady = computed(() => !options.open.value && !active.value)
	function willPresent() { active.value = true }
	async function didPresent(event: Event) {
		presented.value = true
		const commentId = options.selectedCommentId.value
		const modal = event.target
		await nextTick()
		if (presented.value && options.open.value && commentId !== null && options.selectedCommentId.value === commentId && modal instanceof HTMLElement) {
			modal.querySelector<HTMLElement>(`[data-comment-id="${commentId}"] button[data-point-link]`)?.focus()
		}
	}
	function willDismiss() {
		presented.value = false
		options.open.value = false
	}
	async function didDismiss() {
		active.value = false
		await nextTick()
		if (focusReady.value && options.keyboardPositioning.value) options.shell.value?.focus()
	}
	return { presented, focusReady, willPresent, didPresent, willDismiss, didDismiss }
}
