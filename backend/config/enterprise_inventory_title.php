<?php

return [
    // Intentionally empty by default. Only an administrator-approved,
    // independently authenticated trust-key source may populate this map.
    // Never accept a public key supplied by an untrusted certificate or ZIP.
    // No live stock title becomes certified without a trusted matching key.
    'trusted_public_keys' => [],

    // A revoked key ID always overrides the trusted-key map, including for
    // previously stored signatures. Keep identifiers rather than deleting
    // keys so historical attestations retain their signer provenance.
    'revoked_key_ids' => [],
];
