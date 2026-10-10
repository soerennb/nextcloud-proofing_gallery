import { loadState } from '@nextcloud/initial-state'
import { installGalleryFavicons } from './domain/galleryFavicons.ts'
import type { GalleryFaviconLink } from './domain/galleryFavicons.ts'

installGalleryFavicons(loadState<GalleryFaviconLink[]>('proofing_gallery', 'favicons'))
