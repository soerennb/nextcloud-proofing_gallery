import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ getCanonicalLocale: () => 'en-US' }))

import { formatDate, formatDateTime, formatRelative, isOverdue, toDate } from './dateFormat.ts'

describe('date formatting', () => {
	it('keeps calendar dates on their local day', () => {
		expect(formatDate('2026-10-15')).toBe('Oct 15, 2026')
		expect(toDate('2026-10-15')?.getDate()).toBe(15)
	})

	it('formats Unix seconds with and without time', () => {
		const timestamp = Date.UTC(2026, 9, 9, 10, 28) / 1000
		expect(formatDate(timestamp)).toMatch(/Oct (9|10), 2026/)
		expect(formatDateTime(timestamp)).toContain(',')
	})

	it('returns an empty label for missing or invalid values', () => {
		expect(formatDate(null)).toBe('')
		expect(formatDate('not a date')).toBe('')
	})

	it('treats a due date as overdue only after the whole day passed', () => {
		expect(isOverdue('2026-10-15', new Date('2026-10-15T20:00:00'))).toBe(false)
		expect(isOverdue('2026-10-15', new Date('2026-10-16T00:00:01'))).toBe(true)
		expect(isOverdue(null)).toBe(false)
	})

	it('describes recent activity relatively and older activity by date', () => {
		const now = new Date('2026-10-24T12:00:00')
		expect(formatRelative(new Date('2026-10-24T10:00:00'), now)).toBe('2 hours ago')
		expect(formatRelative(new Date('2026-10-23T12:00:00'), now)).toBe('yesterday')
		expect(formatRelative(new Date('2026-10-01T12:00:00'), now)).toBe('Oct 1, 2026')
	})
})
