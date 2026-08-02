<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'

defineProps<{ label: string }>()

const menuId = `gallery-actions-${useId()}`
const trigger = ref<HTMLButtonElement | null>(null)
const menu = ref<HTMLElement | null>(null)
const open = ref(false)
const position = ref({ top: '0px', left: '0px' })

function place() {
	if (!trigger.value) return
	const triggerRect = trigger.value.getBoundingClientRect()
	const menuWidth = menu.value?.offsetWidth ?? 160
	const menuHeight = menu.value?.offsetHeight ?? 96
	const left = Math.min(
		Math.max(8, triggerRect.right - menuWidth),
		window.innerWidth - menuWidth - 8,
	)
	const below = triggerRect.bottom + 4
	const top = below + menuHeight <= window.innerHeight - 8
		? below
		: Math.max(8, triggerRect.top - menuHeight - 4)
	position.value = { top: `${top}px`, left: `${left}px` }
}

function close({ returnFocus = false } = {}) {
	open.value = false
	if (returnFocus) trigger.value?.focus()
}

function toggle() {
	open.value = !open.value
}

function onDocumentPointerDown(event: PointerEvent) {
	const target = event.target as Node
	if (!trigger.value?.contains(target) && !menu.value?.contains(target)) close()
}

function onDocumentKeyDown(event: KeyboardEvent) {
	if (event.key === 'Escape' && open.value) {
		event.preventDefault()
		close({ returnFocus: true })
	}
}

function onMenuKeyDown(event: KeyboardEvent) {
	if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return
	const items = [...(menu.value?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])]
	if (items.length === 0) return
	event.preventDefault()
	const current = items.indexOf(document.activeElement as HTMLElement)
	const index = event.key === 'Home'
		? 0
		: event.key === 'End'
			? items.length - 1
			: event.key === 'ArrowDown'
				? (current + 1) % items.length
				: (current - 1 + items.length) % items.length
	items[index]?.focus()
}

function onMenuClick(event: MouseEvent) {
	if ((event.target as Element).closest('[role="menuitem"]')) close()
}

watch(open, async value => {
	if (!value) return
	await nextTick()
	place()
	menu.value?.querySelector<HTMLElement>('[role="menuitem"]')?.focus()
})

document.addEventListener('pointerdown', onDocumentPointerDown, true)
document.addEventListener('keydown', onDocumentKeyDown)
window.addEventListener('resize', place)
window.addEventListener('scroll', place, true)

onBeforeUnmount(() => {
	document.removeEventListener('pointerdown', onDocumentPointerDown, true)
	document.removeEventListener('keydown', onDocumentKeyDown)
	window.removeEventListener('resize', place)
	window.removeEventListener('scroll', place, true)
})
</script>

<template>
	<div class="gallery-actions">
		<button
			ref="trigger"
			class="gallery-actions__trigger"
			type="button"
			:aria-controls="open ? menuId : undefined"
			:aria-expanded="open"
			:aria-label="label"
			aria-haspopup="menu"
			@click="toggle">
			<span aria-hidden="true">•••</span>
		</button>
		<Teleport to="body">
			<div
				v-if="open"
				:id="menuId"
				ref="menu"
				class="gallery-actions__menu"
				role="menu"
				:aria-label="label"
				:style="position"
				@click="onMenuClick"
				@keydown="onMenuKeyDown">
				<slot />
			</div>
		</Teleport>
	</div>
</template>

<style scoped>
.gallery-actions__trigger {
	display: grid;
	width: 42px;
	height: 42px;
	place-items: center;
	border: 0;
	border-radius: 6px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.gallery-actions__trigger:hover,
.gallery-actions__trigger:focus-visible,
.gallery-actions__trigger[aria-expanded="true"] {
	background: var(--color-background-hover);
}

.gallery-actions__trigger:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}
</style>

<style>
.gallery-actions__menu {
	position: fixed;
	z-index: 10020;
	display: grid;
	min-width: 160px;
	padding: 4px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	box-shadow: 0 2px 8px var(--color-box-shadow);
}

.gallery-actions__menu [role="menuitem"] {
	width: 100%;
	min-height: 38px;
	padding: 6px 10px;
	border: 0;
	border-radius: 5px;
	background: transparent;
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;
}

.gallery-actions__menu [role="menuitem"]:hover,
.gallery-actions__menu [role="menuitem"]:focus-visible {
	background: var(--color-background-hover);
	outline: none;
}
</style>
