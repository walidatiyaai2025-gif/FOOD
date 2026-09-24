# Known Bootstrap Constraints

1. The repository was completely empty; one minimal seed commit on main was technically required before a task branch could exist. All substantive bootstrap changes are on Issue #1's branch and must enter main through PR.
2. The connected GitHub surface does not expose repository-administration mutations for branch protection/rulesets or GitHub Projects v2 creation. Process governance, status labels and CI are versioned, but server-side protection/Project UI require an admin-capable surface.
3. Raw PNGs are about 19 MB and the connected repository writer is text-oriented. The exact archive is pinned by SHA-256, every screen is indexed, and an import/verification script is committed. Raw PNG transport itself is not performed through this connector.
