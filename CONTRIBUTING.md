# Contributing

Thank you for helping improve Proofing Gallery. Use GitHub Discussions for
questions and an issue form for confirmed bugs or proposed features. Security
problems must follow [SECURITY.md](SECURITY.md).

## Development setup

Use Node.js and npm versions from `.nvmrc` and `package.json`, PHP 8.1 or newer,
Composer 2, and Docker Compose:

```bash
nvm use
npm ci
composer install
make dev-up
```

Before submitting a change, run:

```bash
make lint
make test
npm run build
npm run build:docs
make verify-package
```

Add tests for behavior changes and keep public authorization checks on the
server. Never use client visibility as an access-control boundary. Avoid adding
network dependencies to the bundled help; user and administrator documentation
must work offline. When UI or workflow behavior changes, update the README,
English and German user/admin guides, and the changelog in the same change.
Keep `docs/USER-GUIDE.md` synchronized with `docs/en/user-guide.md` and run
`npm run check:docs` before opening the pull request.

## Pull requests

Keep commits focused and use a clear imperative subject. Explain user-visible
behavior, security or privacy impact, compatibility, and validation. Update the
English and German guide together when user or administrator behavior changes.
By contributing, you agree that your work is licensed under
AGPL-3.0-or-later, the repository's license.
