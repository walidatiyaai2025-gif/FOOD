# FOODEX 1.0.77 Image Reconstruction Report

Issue: #1382

## Outcome

- 25/25 launch products have a production image mapping.
- 19 mappings are source-backed content-preserving derivatives from 17 unique authoritative DOCX images.
- 6 mappings use the owner-approved neutral `source_missing_placeholder`; no package/product art was invented or substituted.
- Every derivative is 1200×1200 WebP; the production minimum is 800 px.
- The authoritative DOCX SHA-256 was verified before generation: `1333292c13e6bbddacc26f728485021459f914964af29ec589894086241cc523`.
- Canonical derivative bytes are repository-owned in the deterministic bundle file; every decoded asset is independently SHA-256 pinned in the manifest.
- No file under `apps/customer_app/**` is changed by this lane.

## Fidelity method

Source-backed assets use aspect-preserving Lanczos upscale into a 1200×1200 canvas, a conservative unsharp mask, and WebP quality 78/method 6. The transform does not crop the product, stretch aspect ratio, redraw labels, replace packaging, alter identity, invent variants, or add decorative product content.

For source-missing SKUs, the explicit owner exception on #1382 (comment `6159110747`) is honored with a neutral non-product placeholder that states the official product image is unavailable. Those mappings are distinguishable in the manifest as `source_missing_placeholder`.

## Canonical bundle

- Directory: `backend/database/seeders/fixtures/foodex-launch-image-bundle/`
- Parts: 1 (`00.part`)
- Concatenated bundle SHA-256: `530930173a3893ffa7b555af187ae38350add0a61afbb76d38b282a4a3b44816`
- Format: compact JSON containing 25 base64-encoded WebP derivatives.
- Runtime contract: read the canonical `00.part`, verify bundle SHA-256, decode exactly 25 keys, then verify each decoded asset checksum and dimensions before staging.

## Asset ledger

| SKU | Product | Source | Source px | Method | Output | SHA-256 |
|---|---|---|---:|---|---:|---|
| FDX-LAUNCH-001 | First Choice Strawberry | `image3.png` | 400×383 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `7121ab1931c411eeb21b531af54f16bf73b2b3c63f2a6e7a03ec07766e701117` |
| FDX-LAUNCH-002 | First Choice Mango | `image4.jpeg` | 402×402 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `4c5f642c28779c703344b7a696a4cbf4348551ec5612be88cf7eb878bd0eb511` |
| FDX-LAUNCH-003 | First Choice Molokhia | `image5.png` | 422×404 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `61da8cb5debe165bb5fed7ab8abfd9ac95a61a527588379a3aec6b1d76c05740` |
| FDX-LAUNCH-004 | First Choice Zero Okra | `image6.jpeg` | 394×394 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `5c377f96aed27d2a820d3192229bfe7ea3c1f9533906ee2be95fda1174ab08e0` |
| FDX-LAUNCH-005 | First Choice Premium Okra | `image7.jpeg` | 364×364 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `da6a138957bfb6211b5a9aeddf9cf4664a24c1c035914b079fafa18d41786342` |
| FDX-LAUNCH-006 | First Choice Pommes Frites 12.5 kg | `image8.jpeg` | 389×389 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `2572fa4cbc2cb133123215886c3861bcc2c097cd8d72e1274c4c3d314a23215d` |
| FDX-LAUNCH-007 | First Choice Allumette Fries 12.5 kg | `image8.jpeg` | 389×389 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `2572fa4cbc2cb133123215886c3861bcc2c097cd8d72e1274c4c3d314a23215d` |
| FDX-LAUNCH-008 | FOODEX Crinkle Fries Fingers and Slices 12.5 kg | `image8.jpeg` | 389×389 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `2572fa4cbc2cb133123215886c3861bcc2c097cd8d72e1274c4c3d314a23215d` |
| FDX-LAUNCH-009 | First Choice Natural Mozzarella | `image11.jpeg` | 368×368 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `6e39f252aaf004c341a29cd8d76a2ee23073901c7e73b28cc957b04f2989df1d` |
| FDX-LAUNCH-010 | First Choice Analogue Mozzarella | `image12.jpeg` | 361×361 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `3a5c559f2503e2fe47840e716c78473cb264246b6a9ba23c5bbbe8c7820b9fc7` |
| FDX-LAUNCH-011 | First Choice Premium Yellow Pizza Mozzarella | `image13.jpeg` | 367×367 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `ab3c97df4e63c3a04b302bd3cbbd12c53871f23ddd9e6683577fab2928bbce70` |
| FDX-LAUNCH-012 | First Choice Analogue Mozzarella 400 g | `image14.jpeg` | 340×340 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `d6861aee32b3ec5a948d2b79d663aedfb5ee4cbcb663454a77611193dc8b2fa3` |
| FDX-LAUNCH-013 | First Choice Ketchup Jar 10 kg Grade 1 | `image15.jpeg` | 396×396 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `c4e367da5387ed2e49aa2d321963c23c525f71e3cf83ecc5d32ac3bc8927eb99` |
| FDX-LAUNCH-014 | First Choice Ketchup Jar 10 kg | `image16.jpeg` | 322×322 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `0d8df06e5cb7f0e38a919fd8d2ae6bd196e9524b16e3c72d820aaaed4b101959` |
| FDX-LAUNCH-015 | First Choice Ketchup Sachet 7 g | `image17.jpeg` | 376×376 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `3e63e14d56a3507fa2a5e3c6f8bc3ccc743f0b621d155a33cf79ea83aac8fa5f` |
| FDX-LAUNCH-016 | First Choice Mayonnaise Jar 10 kg | `image18.jpeg` | 376×376 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `20450724a31b87a22abc2d78944d1d6e82a8d6acb0bbe928304c01888686f167` |
| FDX-LAUNCH-017 | First Choice Mustard Jar 5 kg | `no authoritative visual` | — | `source_missing_placeholder` | 1200×1200 | `2ea0dd2b502d9d06f37f7a8f350933afb5c07b199463a73b49ff046e74712ec7` |
| FDX-LAUNCH-018 | First Choice Barbecue Sauce Jar 5 kg | `image19.jpeg` | 344×344 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `4e039b6fd7dab4e5557efe1f21aa958bcda75b056c45cedcbf3589895272d0e7` |
| FDX-LAUNCH-019 | First Choice Harissa Chili Jar | `image20.jpeg` | 388×388 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `1e7bda0646388b50372e1fb3363824813dda6b804a9d43d21114483693029cee` |
| FDX-LAUNCH-020 | First Choice Hot Sauce Jar | `no authoritative visual` | — | `source_missing_placeholder` | 1200×1200 | `25cdc0e19d60771ddbcf64d7d775fc1c7e7fbb0a0b19660e6095fa45603868d2` |
| FDX-LAUNCH-021 | First Choice Pizza Sauce Jar | `no authoritative visual` | — | `source_missing_placeholder` | 1200×1200 | `5853f127a37c55009fee64697163e4f2846697682f71365e7104f00d2d4515fe` |
| FDX-LAUNCH-022 | First Choice Sweet Chili Sauce Jar | `no authoritative visual` | — | `source_missing_placeholder` | 1200×1200 | `c585f2fe132e0de8c1ca330bf760372c11a06412d9cab0daf6a1061ea730e1cf` |
| FDX-LAUNCH-023 | First Choice Strips 900 g | `image22.jpeg` | 402×402 | `content_preserving_upscale_lanczos_unsharp` | 1200×1200 | `3e75bb9d5262c558fa7b779049b60e2817c1716f11135039c6f62b6d3d127727` |
| FDX-LAUNCH-024 | First Choice Pane 900 g | `no authoritative visual` | — | `source_missing_placeholder` | 1200×1200 | `e68406c0021e9968d3d04e495d3b48d591695002f01fa7d420c5c1b49d9c21c2` |
| FDX-LAUNCH-025 | First Choice Shish Tawook 900 g | `no authoritative visual` | — | `source_missing_placeholder` | 1200×1200 | `cb168f2b0137a895193b249798abc65b704201f1b4dae60daa2d2e1395999315` |

## Source-missing exception inventory

The six source-missing SKUs are `FDX-LAUNCH-017`, `FDX-LAUNCH-020`, `FDX-LAUNCH-021`, `FDX-LAUNCH-022`, `FDX-LAUNCH-024`, and `FDX-LAUNCH-025`. They use neutral non-product placeholders only.

## Verification contract

The backend launch seeder validates the manifest, concatenated bundle SHA-256, decoded asset count, per-SKU mapping, source provenance state, decoded WebP dimensions and decoded SHA-256. It stages only verified bytes to `storage/app/public/foodex-launch/products/`. Product-image readiness becomes green independently of the still-separate banner gate.

## Customer freeze

`apps/customer_app/**` remains immutable against frozen baseline `4de5a24b2ca6bd9b448a79ca05f7f44c7d428f4d`. #1382 changes only backend/release image-fidelity artifacts.