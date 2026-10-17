# Main synchronization guidance

When an Issue branch needs the latest `main`, update that existing Issue branch from `origin/main` and push the Issue branch.

Do not open a pull request with `main` as the head and an Issue branch as the base. Repository branch policy is designed for Issue-scoped PR heads, so reverse synchronization PRs can attach failing policy checks to the protected `main` commit even when the synchronization itself contains no product changes.

Use the existing Issue/branch/PR, synchronize there, then rerun exact-head required checks before promotion to `main`.
