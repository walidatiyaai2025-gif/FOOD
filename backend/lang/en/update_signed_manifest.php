<?php

return [
    'manifest_label' => 'Signed publisher manifest (.json)',
    'manifest_help' => 'Upload the detached signed manifest supplied with the Update ZIP by your verified FOODEX publisher. The target version, minimum compatible version, SHA-256, migration declaration and notes are read from this signed file; do not type raw IDs, JSON or checksums.',
    'host_key_missing' => 'HOST ACTION REQUIRED: a verified FOODEX update publisher public key is not pinned for this website. Ask your trusted deployment administrator to provision FOODEX_UPDATE_PUBLISHER_KEY_ID and FOODEX_UPDATE_PUBLISHER_PUBLIC_KEY_B64 securely through cPanel/environment configuration, then rebuild the configuration cache. Obtain the verified publisher key from an independent authorized source, never from the ZIP or its uploader. Do not use sample keys.',
    'signature_caveat' => 'Update signature verification is mandatory before any ZIP is applied. A PHP Sodium check or a valid checksum alone is not release certification: actual signed ZIP upgrades, database/file recovery and hosting privileges still require end-to-end acceptance.',
    'invalid_signature' => 'The Update ZIP was not installed: the publisher manifest or trusted digital signature could not be verified. No application update or restore was started.',
    'upload_staging_unavailable' => 'The Update ZIP could not be staged in the private update directory. No update was started.',
];
