# FOODEX 1.0.122 Update Notes

- Recover canonical release publication from the final protected-main source after the 1.0.121 immutable tag was prematurely published from a pre-merge release branch.
- Preserve the historical v1.0.120 and v1.0.121 GitHub tags, Releases and assets; do not replace, retarget, overwrite or relabel older binaries.
- Carry forward approved Driver B2B/B2C Wallet, responsive Driver Home/Deliveries, encrypted Remember Me, biometric-only saved-session unlock and Sign out; Customer, Van and Laravel Dashboard behavior remains unchanged.
- Customer, Driver and Van Android applications use `1.0.122+122`. Dashboard Update and Laravel Setup must be built from the same final protected-main commit.
- Trial Distribution produces tester Actions artifacts only. Canonical Release Factory publishes immutable release assets, generated distribution and protected-main registry update.
- Validate exact main SHA, APKs x3, Dashboard ZIP/JSON/SHA256, clean Setup installation, release asset digests and registry before deployment or distribution. See #2466, #2464 and #2458.
