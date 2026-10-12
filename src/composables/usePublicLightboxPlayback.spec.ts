import { defineComponent, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { usePublicLightboxPlayback } from './usePublicLightboxPlayback.ts'

afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks() })
describe('lightbox runtime lifetime', () => {
	it('releases a wake lock that resolves after unmount', async () => {
		let resolve!: (lock: WakeLockSentinel) => void
		const request = vi.fn(() => new Promise<WakeLockSentinel>(complete => { resolve = complete }))
		Object.defineProperty(navigator, 'wakeLock', { configurable: true, value: { request } })
		let runtime!: ReturnType<typeof usePublicLightboxPlayback>
		const wrapper = mount(defineComponent({ setup() { runtime = usePublicLightboxPlayback({ shell: ref(null), preview: () => false, initialSlideshow: false, interval: () => 5, busy: () => false, canNext: () => true, next: vi.fn() }); return () => null } }))
		runtime.setSlideshow(true)
		wrapper.unmount()
		const release = vi.fn(async () => {})
		const lock = new EventTarget() as WakeLockSentinel
		Object.defineProperties(lock, { type: { value: 'screen' }, released: { value: false }, release: { value: release } })
		resolve(lock)
		await Promise.resolve()
		expect(release).toHaveBeenCalledOnce()
	})
	it('never advances preview media or requests wake locks', async () => {
		vi.useFakeTimers()
		const request = vi.fn()
		Object.defineProperty(navigator, 'wakeLock', { configurable: true, value: { request } })
		const next = vi.fn()
		let runtime!: ReturnType<typeof usePublicLightboxPlayback>
		const wrapper = mount(defineComponent({ setup() { runtime = usePublicLightboxPlayback({ shell: ref(null), preview: () => true, initialSlideshow: false, interval: () => 5, busy: () => false, canNext: () => true, next }); return () => null } }))
		runtime.setSlideshow(true)
		await vi.advanceTimersByTimeAsync(20000)
		expect(next).not.toHaveBeenCalled()
		expect(request).not.toHaveBeenCalled()
		wrapper.unmount()
	})
})
