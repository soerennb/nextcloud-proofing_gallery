<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import GalleryActionsMenu from './GalleryActionsMenu.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AccountGroupOutlineIcon from 'vue-material-design-icons/AccountGroupOutline.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import LightningBoltOutlineIcon from 'vue-material-design-icons/LightningBoltOutline.vue'
import ShieldAccountOutlineIcon from 'vue-material-design-icons/ShieldAccountOutline.vue'
import type { GalleryWorkspace, GalleryWorkspaceItem } from '../domain/gallerySettingsOptions.ts'

const props = defineProps<{ tabs: GalleryWorkspaceItem[]; active: GalleryWorkspace }>()
const emit = defineEmits<{ select: [workspace: GalleryWorkspace] }>()
const strip = ref<HTMLElement | null>(null)
const primary = computed(() => props.tabs.filter(tab => tab.group === 'primary'))
const more = computed(() => props.tabs.filter(tab => tab.group === 'more'))
const activeMore = computed(() => more.value.find(tab => tab.id === props.active))
const icons = { team: AccountGroupOutlineIcon, automation: LightningBoltOutlineIcon, history: HistoryIcon, privacy: ShieldAccountOutlineIcon }
async function revealActive() {
	await nextTick()
	const item = strip.value?.querySelector<HTMLElement>('[aria-current="page"]')
	if (!item || !strip.value) return
	const rect = item.getBoundingClientRect(), parent = strip.value.getBoundingClientRect()
	if (rect.left < parent.left) strip.value.scrollLeft -= parent.left - rect.left
	else if (rect.right > parent.right) strip.value.scrollLeft += rect.right - parent.right
}
watch(() => props.active, revealActive)
onMounted(() => { revealActive(); window.addEventListener('resize', revealActive) })
onBeforeUnmount(() => window.removeEventListener('resize', revealActive))
</script>

<template>
	<nav class="workspace-navigation" :aria-label="t('proofing_gallery', 'Gallery settings')">
		<div ref="strip" class="workspace-navigation__tabs">
			<button v-for="tab in primary"
				:key="tab.id"
				type="button"
				:aria-current="active === tab.id ? 'page' : undefined"
				@click="emit('select', tab.id)">
				{{ tab.label }}
			</button>
		</div>
		<div v-if="more.length" class="workspace-navigation__more" :class="{ 'workspace-navigation__more--active': activeMore }">
			<GalleryActionsMenu named :label="activeMore?.label ?? t('proofing_gallery', 'More')">
				<template #trigger>
					<DotsHorizontalIcon aria-hidden="true" :size="20" />
					<span>{{ activeMore?.label ?? t('proofing_gallery', 'More') }}</span>
					<ChevronDownIcon aria-hidden="true" :size="16" />
				</template>
				<button v-for="tab in more"
					:key="tab.id"
					class="workspace-menu-item"
					type="button"
					role="menuitem"
					:aria-current="active === tab.id ? 'page' : undefined"
					@click="emit('select', tab.id)">
					<component :is="icons[tab.id as keyof typeof icons]" aria-hidden="true" :size="20" />
					{{ tab.label }}
				</button>
			</GalleryActionsMenu>
		</div>
	</nav>
</template>

<style scoped>
.workspace-navigation { position: sticky; z-index: 12; top: 0; display: flex; min-width: 0; align-items: center; gap: 16px; margin-top: 20px; padding-block: 6px; border-bottom: 1px solid var(--studio-line); background: var(--studio-canvas); }

.workspace-navigation__tabs { display: flex; min-width: 0; flex: 1; gap: 4px; overflow-x: auto; scrollbar-width: thin; }

.workspace-navigation__tabs button { flex: none; min-height: 44px; padding: 0 12px; border: 0; border-radius: 8px; background: transparent; color: var(--studio-muted); font: inherit; font-weight: 650; cursor: pointer; }

.workspace-navigation__tabs button:hover { background: var(--studio-surface-raised); color: var(--studio-ink); }

.workspace-navigation__tabs button[aria-current='page'] { background: var(--studio-accent-soft); color: var(--studio-accent); }

.workspace-navigation__more { position: relative; flex: none; }

.workspace-menu-item { display: flex; align-items: center; gap: 10px; font: inherit; }

.workspace-navigation__more--active :deep(.gallery-actions__trigger) { background: var(--studio-accent-soft); color: var(--studio-accent); }

@media (max-width: 640px) { .workspace-navigation { gap: 8px; margin-top: 12px; } .workspace-navigation__tabs button { padding-inline: 10px; font-size: 14px; } }
</style>
