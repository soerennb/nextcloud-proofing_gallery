import { ref } from 'vue'
import type { Ref } from 'vue'
import type { CollaborationState, MediaItem } from '../publicTypes.ts'

export function usePublicLightboxComments(options: {
	item: Ref<MediaItem | null>
	annotation: Ref<CollaborationState['comments'][number] | null>
	mutate(path: string, method: 'POST' | 'PUT', body: unknown): Promise<boolean>
}) {
	const commentBody = ref('')
	const annotationReplyBody = ref('')
	const editingCommentId = ref<number | null>(null)
	const editingCommentBody = ref('')
	async function addComment() {
		const item = options.item.value
		if (item && commentBody.value.trim() && await options.mutate(`media/${item.id}/comments`, 'POST', { body: commentBody.value, annotation: null })) commentBody.value = ''
	}
	async function addAnnotationReply() {
		const item = options.item.value
		const comment = options.annotation.value
		if (item && comment && annotationReplyBody.value.trim() && await options.mutate(`media/${item.id}/comments`, 'POST', { body: annotationReplyBody.value, parentId: comment.threadId ?? comment.id })) annotationReplyBody.value = ''
	}
	function editComment(comment: CollaborationState['comments'][number]) { editingCommentId.value = comment.id; editingCommentBody.value = comment.body }
	async function saveEditedComment(commentId: number) {
		if (editingCommentBody.value.trim() && await options.mutate(`comments/${commentId}`, 'PUT', { body: editingCommentBody.value })) { editingCommentId.value = null; editingCommentBody.value = '' }
	}
	return { commentBody, annotationReplyBody, editingCommentId, editingCommentBody, addComment, addAnnotationReply, editComment, saveEditedComment }
}
