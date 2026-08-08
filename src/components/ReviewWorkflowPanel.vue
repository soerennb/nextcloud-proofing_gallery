<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import { computed, onMounted, ref } from 'vue'

import { createReviewCalendarEvent, createReviewTalkConversation, deleteReviewTalkConversation, fetchReviewIntegrations, fetchReviewOverview, registerReviewDeckCard, transitionReview } from '../services/galleryApi.ts'
import type { ReviewIntegrationStatus } from '../services/galleryApi.ts'
import type { ReviewLinkOverview, ReviewOverview } from '../types.ts'

const props = defineProps<{ galleryId: number }>()
const overview = ref<ReviewOverview | null>(null)
const loading = ref(true)
const busyLink = ref<number | null>(null)
const integrations = ref<ReviewIntegrationStatus | null>(null)
const selectedCalendar = ref('')
const deckBoards = ref<Array<{ id: number; title: string; stacks?: Array<{ id: number; title: string }> }>>([])
const selectedBoard = ref<number | null>(null)
const selectedStack = ref<number | null>(null)
const loadFailed = ref(false)
const enabledLinks = computed(() => overview.value?.items.filter(link => link.enabled && link.linkStatus === 'active') ?? [])

const labels = computed(() => ({
	awaiting_feedback: t('proofing_gallery', 'Waiting for feedback'),
	submitted: t('proofing_gallery', 'Submitted for decision'),
	changes_requested: t('proofing_gallery', 'Changes requested'),
	approved: t('proofing_gallery', 'Approved'),
}))

async function load() {
	loading.value = true
	loadFailed.value = false
	try {
		overview.value = await fetchReviewOverview(props.galleryId)
	} catch {
		loadFailed.value = true
	} finally { loading.value = false }
	try {
		integrations.value = await fetchReviewIntegrations(props.galleryId)
		selectedCalendar.value ||= integrations.value.calendar.items[0]?.uri ?? ''
		if (integrations.value.deck.available && !deckBoards.value.length) await loadDeck()
	} catch {
		integrations.value = { calendar: { available: false, items: [] }, deck: { available: false }, talk: { available: false }, links: [] }
	}
}

async function loadDeck() {
	try {
		const { data } = await axios.get<Array<{ id: number; title: string; stacks?: Array<{ id: number; title: string }> }>>(generateUrl('/apps/deck/api/v1.0/boards'), { params: { details: true }, headers: { 'OCS-APIRequest': 'true' } })
		deckBoards.value = data.filter(board => board.stacks?.length)
		selectedBoard.value ||= deckBoards.value[0]?.id ?? null
		selectFirstStack()
	} catch { integrations.value!.deck.available = false }
}

function selectFirstStack() { selectedStack.value = deckBoards.value.find(board => board.id === selectedBoard.value)?.stacks?.[0]?.id ?? null }

async function addCalendar(link: ReviewLinkOverview) {
	if (!selectedCalendar.value) return
	busyLink.value = link.linkId
	try { integrations.value = await createReviewCalendarEvent(props.galleryId, link.linkId, selectedCalendar.value); showSuccess(t('proofing_gallery', 'Review deadline added to Calendar.')) } catch { showError(t('proofing_gallery', 'The Calendar event could not be created.')) } finally { busyLink.value = null }
}

async function addDeck(link: ReviewLinkOverview) {
	if (!selectedBoard.value || !selectedStack.value) return
	busyLink.value = link.linkId
	let createdCard: { boardId: number; stackId: number; cardId: number } | null = null
	try {
		const due = link.dueDate ? `${link.dueDate}T17:00:00.000Z` : null
		const { data } = await axios.post<{ id: number }>(generateUrl(`/apps/deck/api/v1.0/boards/${selectedBoard.value}/stacks/${selectedStack.value}/cards`), { title: link.name, type: 'plain', order: 999, description: t('proofing_gallery', 'Client review for gallery #{id}', { id: props.galleryId }), duedate: due }, { headers: { 'OCS-APIRequest': 'true' } })
		createdCard = { boardId: selectedBoard.value, stackId: selectedStack.value, cardId: data.id }
		integrations.value = await registerReviewDeckCard(props.galleryId, link.linkId, selectedBoard.value, selectedStack.value, data.id)
		createdCard = null
		showSuccess(t('proofing_gallery', 'Review card created in Deck.'))
	} catch {
		if (createdCard) {
			await axios.delete(generateUrl(`/apps/deck/api/v1.0/boards/${createdCard.boardId}/stacks/${createdCard.stackId}/cards/${createdCard.cardId}`), { headers: { 'OCS-APIRequest': 'true' } }).catch(() => undefined)
		}
		showError(t('proofing_gallery', 'The Deck card could not be created.'))
	} finally { busyLink.value = null }
}

function linked(linkId: number, provider: 'calendar' | 'deck' | 'talk') { return integrations.value?.links.find(item => item.linkId === linkId && item.provider === provider) }

async function toggleTalk(link: ReviewLinkOverview) {
	busyLink.value = link.linkId
	try {
		integrations.value = linked(link.linkId, 'talk')
			? await deleteReviewTalkConversation(props.galleryId, link.linkId)
			: await createReviewTalkConversation(props.galleryId, link.linkId)
		showSuccess(t('proofing_gallery', linked(link.linkId, 'talk') ? 'Private Talk room created.' : 'Talk room removed.'))
	} catch { showError(t('proofing_gallery', 'The Talk room could not be changed.')) } finally { busyLink.value = null }
}

async function transition(link: ReviewLinkOverview, action: 'approve' | 'request-changes' | 'reopen') {
	busyLink.value = link.linkId
	try {
		overview.value = await transitionReview(props.galleryId, link.linkId, action)
		showSuccess(action === 'approve' ? t('proofing_gallery', 'Review approved.') : action === 'request-changes' ? t('proofing_gallery', 'A new review round is ready.') : t('proofing_gallery', 'Review reopened.'))
	} catch { showError(t('proofing_gallery', 'The review state changed. Reload and try again.')) } finally { busyLink.value = null }
}

function formatDate(value: string | number | null) {
	if (!value) return ''
	const date = typeof value === 'number' ? new Date(value * 1000) : new Date(`${value}T12:00:00`)
	return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date)
}

onMounted(load)
</script>

<template>
	<section class="review-panel" aria-labelledby="review-panel-title">
		<header>
			<div>
				<h3 id="review-panel-title">
					{{ t('proofing_gallery', 'Client decisions') }}
				</h3><p>{{ t('proofing_gallery', 'Move each client link through clear, traceable review rounds.') }}</p>
			</div>
			<NcButton variant="tertiary" :disabled="loading" @click="load">
				{{ t('proofing_gallery', 'Refresh') }}
			</NcButton>
		</header>
		<p v-if="loading" role="status">
			{{ t('proofing_gallery', 'Loading review rounds…') }}
		</p>
		<div v-else-if="loadFailed" class="review-panel__empty" role="alert">
			<strong>{{ t('proofing_gallery', 'Review rounds could not be loaded.') }}</strong>
			<NcButton variant="tertiary" @click="load">
				{{ t('proofing_gallery', 'Try again') }}
			</NcButton>
		</div>
		<div v-else-if="!enabledLinks.length" class="review-panel__empty">
			<strong>{{ t('proofing_gallery', 'No review round is active') }}</strong>
			<span>{{ t('proofing_gallery', 'Enable review rounds on a client link in Share.') }}</span>
		</div>
		<div v-else class="review-panel__list">
			<div v-if="integrations?.calendar.available || integrations?.deck.available" class="review-panel__integrations">
				<label v-if="integrations.calendar.available"><span>{{ t('proofing_gallery', 'Calendar') }}</span><select v-model="selectedCalendar"><option v-for="calendar in integrations.calendar.items" :key="calendar.uri" :value="calendar.uri">{{ calendar.name }}</option></select></label>
				<label v-if="integrations.deck.available"><span>{{ t('proofing_gallery', 'Deck board') }}</span><select v-model="selectedBoard" @change="selectFirstStack"><option v-for="board in deckBoards" :key="board.id" :value="board.id">{{ board.title }}</option></select></label>
				<label v-if="integrations.deck.available"><span>{{ t('proofing_gallery', 'Deck stack') }}</span><select v-model="selectedStack"><option v-for="stack in deckBoards.find(board => board.id === selectedBoard)?.stacks ?? []" :key="stack.id" :value="stack.id">{{ stack.title }}</option></select></label>
			</div>
			<article v-for="link in enabledLinks" :key="link.linkId" :data-state="link.current?.status">
				<div class="review-panel__state">
					<span>{{ t('proofing_gallery', 'Round {round}', { round: link.current?.round ?? 1 }) }}</span><strong>{{ labels[link.current?.status ?? 'awaiting_feedback'] }}</strong>
				</div>
				<div class="review-panel__main">
					<h4>{{ link.name }}</h4><p v-if="link.current?.submittedBy">
						{{ t('proofing_gallery', 'Submitted by {name}', { name: link.current.submittedBy }) }}
					</p><p v-else-if="link.dueDate">
						{{ t('proofing_gallery', 'Due {date}', { date: formatDate(link.dueDate) }) }}
					</p>
				</div>
				<div v-if="overview?.canEdit" class="review-panel__actions">
					<NcButton v-if="link.current?.status === 'submitted'"
						variant="primary"
						:disabled="busyLink === link.linkId"
						@click="transition(link, 'approve')">
						{{ t('proofing_gallery', 'Approve') }}
					</NcButton>
					<NcButton v-if="link.current?.status === 'submitted'" :disabled="busyLink === link.linkId" @click="transition(link, 'request-changes')">
						{{ t('proofing_gallery', 'Request changes') }}
					</NcButton>
					<NcButton v-if="link.current?.status === 'approved'" :disabled="busyLink === link.linkId" @click="transition(link, 'reopen')">
						{{ t('proofing_gallery', 'Reopen') }}
					</NcButton>
					<a v-if="linked(link.linkId, 'calendar')?.remote.url" :href="linked(link.linkId, 'calendar')?.remote.url" target="_blank">{{ t('proofing_gallery', 'Open Calendar') }}</a><NcButton v-else-if="integrations?.calendar.available && link.dueDate"
						variant="tertiary"
						:disabled="busyLink === link.linkId"
						@click="addCalendar(link)">
						{{ t('proofing_gallery', 'Add to Calendar') }}
					</NcButton>
					<a v-if="linked(link.linkId, 'deck')?.remote.url" :href="linked(link.linkId, 'deck')?.remote.url" target="_blank">{{ t('proofing_gallery', 'Open Deck card') }}</a><NcButton v-else-if="integrations?.deck.available && selectedStack"
						variant="tertiary"
						:disabled="busyLink === link.linkId"
						@click="addDeck(link)">
						{{ t('proofing_gallery', 'Create Deck card') }}
					</NcButton>
					<a v-if="linked(link.linkId, 'talk')?.remote.url" :href="linked(link.linkId, 'talk')?.remote.url" target="_blank">{{ t('proofing_gallery', 'Open Talk room') }}</a><NcButton v-if="integrations?.talk.available"
						variant="tertiary"
						:disabled="busyLink === link.linkId"
						@click="toggleTalk(link)">
						{{ linked(link.linkId, 'talk') ? t('proofing_gallery', 'Remove Talk room') : t('proofing_gallery', 'Create private Talk room') }}
					</NcButton>
				</div>
				<details v-if="link.history.length > 1">
					<summary>{{ t('proofing_gallery', 'Round history') }}</summary><ol>
						<li v-for="round in link.history" :key="round.round">
							<span>{{ t('proofing_gallery', 'Round {round}', { round: round.round }) }}</span><strong>{{ labels[round.status] }}</strong><time>{{ formatDate(round.updatedAt) }}</time>
						</li>
					</ol>
				</details>
			</article>
		</div>
	</section>
</template>

<style scoped src="../styles/ReviewWorkflowPanel.css"></style>
