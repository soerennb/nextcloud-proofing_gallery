import { describe, expect, it } from 'vitest'
import { calculateMediaGridLayout } from './mediaGridLayout.ts'

describe('calculateMediaGridLayout', () => {
	it('uses one deterministic geometry for rows and total height', () => {
		const layout = calculateMediaGridLayout({ containerWidth: 1000, itemCount: 10, minItemWidth: 210, gap: 12, itemAspectRatio: 4 / 3 })
		expect(layout.columns).toBe(4)
		expect(layout.rows).toBe(3)
		expect(layout.totalHeight).toBeCloseTo(layout.rows * (layout.rowHeight + 12) - 12)
	})

	it('honors list and featured-grid constraints', () => {
		expect(calculateMediaGridLayout({ containerWidth: 900, itemCount: 3, minItemWidth: 360, maxItemWidth: 520, maxColumns: 3, gap: 8, itemAspectRatio: 4 / 3 }).columns).toBe(2)
		expect(calculateMediaGridLayout({ containerWidth: 900, itemCount: 3, minItemWidth: 360, gap: 8, itemAspectRatio: 4 / 3, list: true }).rowHeight).toBe(94)
	})

	it('keeps every mobile item in a one-column document flow', () => {
		const layout = calculateMediaGridLayout({ containerWidth: 378, itemCount: 87, minItemWidth: 230, gap: 6, itemAspectRatio: 4 / 3 })

		expect(layout.columns).toBe(1)
		expect(layout.rows).toBe(87)
		expect(layout.totalHeight).toBeCloseTo(layout.rows * (layout.rowHeight + 6) - 6)
	})
})
