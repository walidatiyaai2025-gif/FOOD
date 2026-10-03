# FOOD #823 B2B visual trial handoff

STATUS: WAITING_USER_ACCEPTANCE
BRANCH: `feat/823-b2b-home-catalog-visual-trial`
PR: #824
MERGE: FORBIDDEN UNTIL USER EXPLICITLY APPROVES THE TEST APK

## Scope already implemented
- Separate B2B Home and Products/Catalog routes.
- Two-column B2B product cards for mobile.
- Product cards show brand instead of category metadata.
- Cart icon removed from product cards; add-to-cart remains as a text-only button.
- Product details now expose the product brand.
- Wholesale purple palette replaced with FOODEX green palette.
- Customer app icon replaced from the user-supplied FOODEX leaf artwork.

## Worker continuation contract
1. Continue only on `feat/823-b2b-home-catalog-visual-trial`.
2. Do not merge PR #824 and do not push these changes to `main`.
3. Fix any red CI on this same branch.
4. Produce a Customer Android tester APK from the latest branch HEAD.
5. Hand the APK to the user for manual acceptance.
6. Merge only after an explicit user message approving the tested APK.

<!-- foodex-worker-state:v1 -->
STATE: WAITING_USER_ACCEPTANCE
OWNER: ChatGPT-handoff-823
BRANCH: feat/823-b2b-home-catalog-visual-trial
PR: 824
BLOCKER: manual_user_acceptance
NEXT_ACTION: fix any CI red on current HEAD, build Customer APK, give APK to user, DO NOT MERGE
