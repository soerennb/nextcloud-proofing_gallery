export type Rgb = [number, number, number]

export function hexRgb(value: string): Rgb {
	const normalized = value.replace('#', '')
	if (!/^[\da-f]{6}$/i.test(normalized)) return [232, 93, 74]
	return [0, 2, 4].map(offset => Number.parseInt(normalized.slice(offset, offset + 2), 16)) as Rgb
}

export function contrastRgb(color: '#000000' | '#ffffff'): string {
	return color === '#000000' ? '0, 0, 0' : '255, 255, 255'
}

export function readableText([red, green, blue]: Rgb): '#000000' | '#ffffff' {
	return (red * 299 + green * 587 + blue * 114) / 1000 > 150 ? '#000000' : '#ffffff'
}

export function mixHex(source: Rgb, target: Rgb, amount: number): string {
	return `#${source.map((channel, index) => Math.round(channel + (target[index]! - channel) * amount).toString(16).padStart(2, '0')).join('')}`
}
