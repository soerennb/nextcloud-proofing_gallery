import { describe, expect, it, vi } from 'vitest'

import { serialTask } from './serialTask.ts'

describe('serialTask', () => {
	it('coalesces overlapping requests into one queued rerun', async () => {
		let release!: () => void
		const task = vi.fn().mockImplementationOnce(() => new Promise<void>(resolve => { release = resolve })).mockResolvedValue(undefined)
		const run = serialTask(task)
		const first = run()
		const second = run()
		expect(first).toBe(second)
		expect(task).toHaveBeenCalledTimes(1)
		release()
		await first
		expect(task).toHaveBeenCalledTimes(2)
	})
})
