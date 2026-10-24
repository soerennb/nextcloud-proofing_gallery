import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { ref } from 'vue'

export interface MediaFeedbackCounts { likes: number; selections: number; comments: number }

const CHUNK = 200

// Client feedback per file for the owner grid; failures leave the grid untouched.
export function useOwnerMediaFeedback(galleryId: () => number) {
	const feedback = ref<Record<number, MediaFeedbackCounts>>({})

	async function loadFeedback(fileIds: number[], reset = false) {
		if (reset) feedback.value = {}
		const missing = fileIds.filter(id => !(id in feedback.value))
		for (let start = 0; start < missing.length; start += CHUNK) {
			const chunk = missing.slice(start, start + CHUNK)
			try {
				const { data } = await axios.get<{ items: Record<string, MediaFeedbackCounts> }>(
					generateOcsUrl('/apps/proofing_gallery/api/v1/galleries/{id}/media/feedback', { id: galleryId() }),
					{ params: { fileIds: chunk.join(','), format: 'json' } },
				)
				feedback.value = { ...feedback.value, ...Object.fromEntries(Object.entries(data.items).map(([id, counts]) => [Number(id), counts])) }
			} catch {
				return
			}
		}
	}

	return { feedback, loadFeedback }
}
