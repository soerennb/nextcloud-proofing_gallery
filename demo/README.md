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

Library v3 contains 48 images across seven coherent series. The original five
series remain stable and are joined by the Northline Objects brand launch and
the Summit Run participant-delivery event. Each standard series has three
portrait and three landscape photographs; the event has six of each so album
scopes and recipient links can be evaluated with realistic material.

The seeded galleries cover The Shoreline Edit showcase, Studio No. 7 client
proofing, Northern Spaces architecture, Live Session delivery, Editorial Edit
culling, Community Press guest uploads, Northline Objects brand delivery, and
Summit Run event delivery. Summit Run includes shared highlights, group
folders, private recipient folders, an ignored internal folder, a released
wave, and fictional PIN/contact data. Never use real client or recipient data
in the persistent Studio or in approved screenshots.
