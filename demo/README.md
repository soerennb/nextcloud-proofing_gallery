# Proofing Gallery demo library

The persistent local studio uses the versioned manifest in
`demo/library-manifest.json`. The image binaries intentionally live below
`.local/demo-library/` and are ignored by Git. This keeps generated media out
of the application package while preserving its provenance and checksums.

Run `make studio-library-check` before seeding. Run `make studio-seed` to create
or refresh the English demo galleries in the isolated loopback-only studio.
The seeder refuses non-loopback URLs.

The images were generated for this repository with OpenAI ImageGen. They do
not depict known people. Do not replace a file without updating its prompt,
dimensions, and SHA-256 checksum in the manifest.

Library v2 contains 30 images across five coherent series. Every series has
three portrait and three landscape photographs so grid, masonry, list,
lightbox, and responsive behavior can be evaluated with realistic material.
