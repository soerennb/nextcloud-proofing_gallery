<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { computed, onMounted, ref } from 'vue'

import {
	deleteNotificationSubscription,
	fetchManagers,
	fetchNotificationSubscriptions,
	saveNotificationSubscription,
} from '../services/galleryApi.ts'
import type { Gallery, NotificationEventType, NotificationSubscription } from '../types.ts'

const props = defineProps<{ gallery: Gallery }>()
const subscriptions = ref<NotificationSubscription[]>([])
const recipients = ref<Array<{ uid: string; label: string }>>([])
const loading = ref(true)
const saving = ref(false)
const recipientUid = ref(props.gallery.ownerUid)
const frequency = ref<'immediate' | 'daily'>('daily')
const locale = ref<'auto' | 'en' | 'de'>('auto')
const eventTypes = ref<NotificationEventType[]>(['comment.created', 'selection.created', 'upload.received'])
const eventOptions: Array<{ value: NotificationEventType; label: string }> = [
	{ value: 'comment.created', label: t('proofing_gallery', 'New comments') },
	{ value: 'comment.updated', label: t('proofing_gallery', 'Edited comments') },
	{ value: 'selection.created', label: t('proofing_gallery', 'New selections') },
	{ value: 'like.changed', label: t('proofing_gallery', 'Likes') },
	{ value: 'color.changed', label: t('proofing_gallery', 'Color states') },
	{ value: 'upload.received', label: t('proofing_gallery', 'New uploads') },
	{ value: 'upload.accepted', label: t('proofing_gallery', 'Accepted uploads') },
	{ value: 'upload.rejected', label: t('proofing_gallery', 'Rejected uploads') },
]
const selectedExisting = computed(() => subscriptions.value.find(item => item.recipientUid === recipientUid.value))

async function load() {
	loading.value = true
	try {
		const [items, managers] = await Promise.all([
			fetchNotificationSubscriptions(props.gallery.id),
			fetchManagers(props.gallery.id),
		])
		subscriptions.value = items
		recipients.value = [
			{ uid: props.gallery.ownerUid, label: t('proofing_gallery', '{name} (owner)', { name: props.gallery.ownerUid }) },
			...managers.filter(manager => manager.type === 'user').map(manager => ({ uid: manager.principalId, label: manager.principalId })),
		]
		selectRecipient()
	} catch {
		showError(t('proofing_gallery', 'Notification subscriptions could not be loaded.'))
	} finally {
		loading.value = false
	}
}

function selectRecipient() {
	const existing = subscriptions.value.find(item => item.recipientUid === recipientUid.value)
	if (!existing) {
		frequency.value = 'daily'
		locale.value = 'auto'
		eventTypes.value = ['comment.created', 'selection.created', 'upload.received']
		return
	}
	frequency.value = existing.frequency
	locale.value = existing.locale
	eventTypes.value = [...existing.eventTypes]
}

async function save() {
	if (eventTypes.value.length === 0) return
	saving.value = true
	try {
		const subscription = await saveNotificationSubscription(props.gallery.id, {
			recipientUid: recipientUid.value,
			eventTypes: eventTypes.value,
			frequency: frequency.value,
			locale: locale.value,
		})
		const index = subscriptions.value.findIndex(item => item.id === subscription.id)
		if (index === -1) subscriptions.value.push(subscription)
		else subscriptions.value[index] = subscription
		showSuccess(t('proofing_gallery', 'Notification subscription saved.'))
	} catch {
		showError(t('proofing_gallery', 'The notification subscription could not be saved. The recipient needs an email address.'))
	} finally {
		saving.value = false
	}
}

async function remove() {
	if (!selectedExisting.value) return
	try {
		await deleteNotificationSubscription(props.gallery.id, selectedExisting.value.id)
		subscriptions.value = subscriptions.value.filter(item => item.id !== selectedExisting.value?.id)
		selectRecipient()
		showSuccess(t('proofing_gallery', 'Notification subscription removed.'))
	} catch {
		showError(t('proofing_gallery', 'The notification subscription could not be removed.'))
	}
}

onMounted(load)
defineExpose({ load })
</script>

<template>
	<section class="notification-panel" aria-labelledby="notification-title">
		<header>
			<h2 id="notification-title">
				{{ t('proofing_gallery', 'Email notifications') }}
			</h2>
			<p>{{ t('proofing_gallery', 'Notifications are off until you explicitly subscribe an eligible person.') }}</p>
		</header>
		<div v-if="loading" class="notification-loading">
			<NcLoadingIcon :size="24" />
		</div>
		<template v-else>
			<div class="notification-fields">
				<label>
					<span>{{ t('proofing_gallery', 'Recipient') }}</span>
					<select v-model="recipientUid" name="notificationRecipient" @change="selectRecipient">
						<option v-for="recipient in recipients" :key="recipient.uid" :value="recipient.uid">{{ recipient.label }}</option>
					</select>
				</label>
				<label>
					<span>{{ t('proofing_gallery', 'Delivery') }}</span>
					<select v-model="frequency" name="notificationFrequency">
						<option value="daily">{{ t('proofing_gallery', 'Daily digest') }}</option>
						<option value="immediate">{{ t('proofing_gallery', 'As soon as possible') }}</option>
					</select>
				</label>
				<label>
					<span>{{ t('proofing_gallery', 'Email language') }}</span>
					<select v-model="locale" name="notificationLocale">
						<option value="auto">{{ t('proofing_gallery', 'Gallery language') }}</option>
						<option value="en">English</option>
						<option value="de">Deutsch</option>
					</select>
				</label>
			</div>
			<fieldset class="notification-events">
				<legend>{{ t('proofing_gallery', 'Events') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="option in eventOptions"
					:key="option.value"
					v-model="eventTypes"
					type="checkbox"
					:value="option.value">
					{{ option.label }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<p v-if="eventTypes.length === 0" class="notification-warning">
				{{ t('proofing_gallery', 'Choose at least one event.') }}
			</p>
			<div class="notification-actions">
				<NcButton variant="primary" :disabled="saving || eventTypes.length === 0" @click="save">
					{{ saving ? t('proofing_gallery', 'Saving…') : selectedExisting ? t('proofing_gallery', 'Update subscription') : t('proofing_gallery', 'Subscribe') }}
				</NcButton>
				<NcButton v-if="selectedExisting" variant="tertiary" @click="remove">
					{{ t('proofing_gallery', 'Remove subscription') }}
				</NcButton>
			</div>
		</template>
	</section>
</template>

<style scoped>
.notification-panel {
	display: grid;
	gap: 16px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.notification-panel h2,
.notification-panel p {
	margin: 0;
}

.notification-panel h2 {
	font-size: 20px;
}

.notification-panel header p,
.notification-warning {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.notification-fields {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 8px;
}

.notification-fields label {
	display: grid;
	gap: 5px;
	font-size: 13px;
}

.notification-fields select {
	min-height: 44px;
	padding: 0 10px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.notification-events {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 4px 16px;
	margin: 0;
	padding: 0;
	border: 0;
}

.notification-events legend {
	grid-column: 1 / -1;
	margin-bottom: 4px;
	font-weight: 600;
}

.notification-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.notification-loading {
	display: grid;
	min-height: 70px;
	place-items: center;
}

@media (max-width: 700px) {
	.notification-fields,
	.notification-events {
		grid-template-columns: 1fr;
	}
}
</style>
