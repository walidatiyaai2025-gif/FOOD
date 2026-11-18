<?php

return [
    'title' => 'Hosting prerequisites for ZIP updates',
    'description' => 'Read-only checks under the website PHP account. These checks do not write files, change permissions or certify that a ZIP update or rollback is safe.',
    'action_required' => 'HOST ACTION REQUIRED: one or more filesystem prerequisites are unavailable. Ask your hosting provider to correct the PHP account ownership/ACL in cPanel before a non-production update test.',
    'filesystem_precheck_only' => 'Basic filesystem preflight passed. This is not proof of free disk space, safe rollback, database tools, trusted signing or production readiness.',
    'precheck_ok' => 'Basic read-only permission check passed; an actual write and restore test is still required.',
    'host_action_required' => 'Host action required: this prerequisite could not be verified.',
    'manual_verification' => 'In cPanel, verify PHP limits (upload_max_filesize and post_max_size), available disk space for ZIP + file/database backups, mysqldump/restore access, Document Root, public storage mapping and Cron/queue operation. These external capabilities cannot be certified by this page. Do not use chmod 777 or expose private storage to the web.',
    'signature_not_certified' => 'Security gate BLOCKED: the current ZIP upload checks a submitted SHA-256 but does not yet authenticate a trusted publisher signature. Do not treat this screen as approved for production ZIP updates.',
    'checks' => [
        'zip_extension' => [
            'label' => 'PHP ZIP support',
            'help' => 'Use cPanel Select PHP Version / MultiPHP Manager to enable zip for this domain. If the extension is unavailable, ask the hosting provider.',
        ],
        'app_storage' => [
            'label' => 'Private Laravel storage',
            'help' => 'The website PHP account needs write access to backend/storage. Correct the file owner/ACL in cPanel or through hosting support; do not use world-writable permissions.',
        ],
        'cache' => [
            'label' => 'Laravel bootstrap cache',
            'help' => 'The website PHP account needs write access to backend/bootstrap/cache for safe configuration rebuilds after a ZIP update.',
        ],
        'update_upload' => [
            'label' => 'Private update upload staging',
            'help' => 'Allow the website PHP account to create private ZIP staging files under backend/storage/app/private/updates/incoming. The path must not be a symlink or web-accessible.',
        ],
        'update_lock' => [
            'label' => 'Exclusive update lock',
            'help' => 'Permit creating the private updater execution lock under backend/storage/app/private/updates. An existing lock must be a writable regular file, not a symlink; contact the host to correct ownership.',
        ],
        'backup_directory' => [
            'label' => 'Private update backup destination',
            'help' => 'Allow the PHP account to create and verify backups in the private configured update backup directory; normally backend/storage/app/system/update-backups. Keep this outside the web root.',
        ],
        'release_directory' => [
            'label' => 'Application release files',
            'help' => 'The hosting PHP account must be able to replace approved application files in the deployment release directory during maintenance. Ask your host to correct ownership/ACL; do not expose the private backend as Document Root.',
        ],
        'sodium_signature' => [
            'label' => 'PHP Sodium (Ed25519 signature prerequisite)',
            'help' => 'Ask your cPanel host to enable PHP sodium with Ed25519 detached-signature verification. The updater also requires an independently verified publisher key pinned on this host. This read-only check does not certify a signed ZIP, the publisher, or a successful recovery.',
        ],
        'process_execution' => [
            'label' => 'PHP child-process execution for backup and restore',
            'help' => 'Ask your cPanel host to allow the website PHP account to run the controlled database backup/restore process (proc_open/proc_close). A disabled PHP function blocks safe ZIP updates. Do not enable unrestricted shell access or run Composer or Artisan manually on production; this precheck executes nothing.',
        ],
        'backup_cli_tools' => [
            'label' => 'Database backup and recovery client binaries',
            'help' => 'For MySQL/MariaDB, ask the hosting provider to expose executable mysqldump and mysql clients to PHP; PostgreSQL needs pg_dump and pg_restore. Confirm the database account can create and restore full backups, available disk space, and writable private backup storage. A detected executable is not proof of restore permissions. Test an isolated backup and restore before an update.',
        ],
        'upload_temporary' => [
            'label' => 'PHP upload temporary directory',
            'help' => 'The website PHP account needs a writable PHP upload temporary directory. Review upload_tmp_dir, open_basedir, disk space and upload limits in cPanel; a read-only directory check is not proof that a large ZIP upload can complete. Never make the directory world-writable.',
        ],
    ],
];
