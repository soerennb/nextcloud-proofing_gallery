import { describe, expect, it } from 'vitest'
import { calculateMediaGridLayout, calculateMediaLayout } from './mediaGridLayout.ts'

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

describe('calculateMediaLayout', () => {
	it('builds filled justified rows without stretching the final row', () => {
		const layout = calculateMediaLayout({
			containerWidth: 1000,
			aspectRatios: [1.5, 1.5, 1.5, 1.5, 2 / 3],
			mode: 'grid',
			gap: 10,
			minItemWidth: 230,
			targetRowHeight: 210,
			listRowHeight: 172,
		})
		const firstRow = layout.positions.filter(position => position.y === 0)
		const firstRowWidth = firstRow.reduce((sum, position) => sum + position.width, 0) + (firstRow.length - 1) * 10
		expect(firstRowWidth).toBeCloseTo(1000)
		const last = layout.positions.at(-1)!
		expect(last.height).toBe(210)
		expect(last.width).toBeCloseTo(140)
		expect(last.width).toBeLessThan(1000)
	})

	it('preserves portrait and landscape ratios in a mobile column', () => {
		const layout = calculateMediaLayout({
			containerWidth: 390,
			aspectRatios: [2 / 3, 3 / 2],
			mode: 'grid',
			gap: 6,
			minItemWidth: 230,
			targetRowHeight: 210,
			listRowHeight: 132,
			singleColumn: true,
		})
		expect(layout.positions[0].height).toBeCloseTo(585)
		expect(layout.positions[1].height).toBeCloseTo(260)
		expect(layout.totalHeight).toBeCloseTo(851)
	})

	it('uses useful fixed contact-sheet rows', () => {
		const layout = calculateMediaLayout({
			containerWidth: 900,
			aspectRatios: [2 / 3, 3 / 2],
			mode: 'list',
			gap: 8,
			minItemWidth: 230,
			targetRowHeight: 210,
			listRowHeight: 172,
		})
		expect(layout.positions.map(position => position.height)).toEqual([172, 172])
		expect(layout.totalHeight).toBe(352)
	})
})
