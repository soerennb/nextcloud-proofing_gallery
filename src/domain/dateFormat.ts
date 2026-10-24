import { getCanonicalLocale } from '@nextcloud/l10n'

export type DateInput = number | string | Date | null | undefined

// Normalizes Unix seconds, calendar dates (`YYYY-MM-DD`, interpreted as local
// noon so time zones cannot shift the day) and Date instances.
export function toDate(value: DateInput): Date | null {
	if (value === null || value === undefined || value === '') return null
	if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value
	const date = typeof value === 'number'
		? new Date(value * 1000)
		: /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T12:00:00`) : new Date(value)
	return Number.isNaN(date.getTime()) ? null : date
}

function locale(): string | undefined {
	try {
		return getCanonicalLocale() || undefined
	} catch {
		return undefined
	}
}

// One locale-aware date style for every owner and guest surface.
export function formatDate(value: DateInput, withTime = false): string {
	const date = toDate(value)
	if (!date) return ''
	return new Intl.DateTimeFormat(locale(), withTime ? { dateStyle: 'medium', timeStyle: 'short' } : { dateStyle: 'medium' }).format(date)
}

export function formatDateTime(value: DateInput): string {
	return formatDate(value, true)
}

// A calendar due date is overdue once the whole due day has passed.
export function isOverdue(dueDate: string | null | undefined, now = new Date()): boolean {
	if (!dueDate || !/^\d{4}-\d{2}-\d{2}$/.test(dueDate)) return false
	return new Date(`${dueDate}T23:59:59.999`).getTime() < now.getTime()
}

// Short relative time such as “2 hours ago”, falling back to the date after a week.
export function formatRelative(value: DateInput, now = new Date()): string {
	const date = toDate(value)
	if (!date) return ''
	const seconds = Math.round((date.getTime() - now.getTime()) / 1000)
	const absolute = Math.abs(seconds)
	if (absolute >= 7 * 86400) return formatDate(date)
	const [amount, unit]: [number, Intl.RelativeTimeFormatUnit] = absolute < 60
		? [seconds, 'second']
		: absolute < 3600
			? [Math.round(seconds / 60), 'minute']
			: absolute < 86400 ? [Math.round(seconds / 3600), 'hour'] : [Math.round(seconds / 86400), 'day']
	return new Intl.RelativeTimeFormat(locale(), { numeric: 'auto' }).format(amount, unit)
}
