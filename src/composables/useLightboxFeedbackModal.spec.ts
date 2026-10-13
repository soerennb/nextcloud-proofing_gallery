import { nextTick, ref } from 'vue'
import { describe, expect, it } from 'vitest'
import { useLightboxFeedbackModal } from './useLightboxFeedbackModal.ts'

function setup() {
	const element = document.createElement('div')
	element.tabIndex = -1
	document.body.append(element)
	const open = ref(true), keyboardPositioning = ref(false), selectedCommentId = ref<number | null>(null)
	const state = useLightboxFeedbackModal({ open, keyboardPositioning, selectedCommentId, shell: ref(element) })
	return { ...state, open, keyboardPositioning, selectedCommentId, element }
}

describe('feedback modal focus lifecycle', () => {
	it('waits for dismissal before handing keyboard positioning to the lightbox', async () => {
		const state = setup()
		try {
			state.willPresent()
			state.keyboardPositioning.value = true
			state.open.value = false
			expect(state.focusReady.value).toBe(false)
			state.willDismiss()
			expect(document.activeElement).not.toBe(state.element)
			await state.didDismiss()
			expect(state.focusReady.value).toBe(true)
			expect(document.activeElement).toBe(state.element)
		} finally { state.element.remove() }
	})
	it('does not restore old focus after feedback reopens during dismissal', async () => {
		const state = setup()
		try {
			state.willPresent()
			state.keyboardPositioning.value = true
			state.willDismiss()
			const dismissal = state.didDismiss()
			state.open.value = true
			state.willPresent()
			await dismissal
			await nextTick()
			expect(state.focusReady.value).toBe(false)
			expect(document.activeElement).not.toBe(state.element)
		} finally { state.element.remove() }
	})
})
