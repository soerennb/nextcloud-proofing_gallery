# Development

The canonical source repository is
[soerennb/nextcloud-proofing_gallery](https://github.com/soerennb/nextcloud-proofing_gallery).

See the detailed [development guide](https://github.com/soerennb/nextcloud-proofing_gallery/blob/main/docs/DEVELOPMENT.md),
[architecture](https://github.com/soerennb/nextcloud-proofing_gallery/blob/main/docs/ARCHITECTURE.md),
[privacy model](https://github.com/soerennb/nextcloud-proofing_gallery/blob/main/docs/PRIVACY.md),
[operations guide](https://github.com/soerennb/nextcloud-proofing_gallery/blob/main/docs/OPERATIONS.md),
and [contribution guide](https://github.com/soerennb/nextcloud-proofing_gallery/blob/main/CONTRIBUTING.md).
Public interfaces and security expectations must remain covered by unit,
browser, package, and compatibility tests.

Gallery favicons use the silhouette in `img/app.svg`, with a white symbol on a
fixed blue background in `img/favicon.svg`. After changing the vector, update
the Safari silhouette in `img/favicon-mask.svg` and rebuild the committed ICO
and touch icon with the canonical container renderer:

```bash
docker compose exec -T --user "$(id -u):$(id -g)" nextcloud php custom_apps/proofing_gallery/scripts/build-favicons.php
```

The renderer is only needed for development. Runtime links use content hashes
and direct app paths, bypassing the Nextcloud theming favicon cache. The gallery
page, public gallery and gallery-unavailable page use these icons; other
Nextcloud pages and the design preview iframe retain their existing icons.
