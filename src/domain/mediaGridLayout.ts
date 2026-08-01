export interface MediaGridLayoutInput {
	containerWidth: number
	itemCount: number
	minItemWidth: number
	gap: number
	itemAspectRatio: number
	itemExtraHeight?: number
	maxItemWidth?: number
	maxColumns?: number
	list?: boolean
}

export function calculateMediaGridLayout(input: MediaGridLayoutInput) {
	const width = Math.max(1, input.containerWidth)
	const fitting = Math.max(1, Math.floor((width + input.gap) / (input.minItemWidth + input.gap)))
	const columns = input.list ? 1 : Math.min(fitting, Math.max(1, input.maxColumns ?? fitting))
	const rows = Math.ceil(input.itemCount / columns)
	const available = (width - input.gap * (columns - 1)) / columns
	const itemWidth = input.maxItemWidth === undefined ? available : Math.min(available, input.maxItemWidth)
	const rowHeight = input.list ? 94 : Math.max(120, itemWidth / input.itemAspectRatio + (input.itemExtraHeight ?? 0))
	return { columns, rows, itemWidth, rowHeight, totalHeight: Math.max(0, rows * (rowHeight + input.gap) - input.gap) }
}
