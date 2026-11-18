<?php

// Trust must come from an owner-verified, host-controlled environment value,
// not from the uploaded ZIP, its manifest or the administrator's form.
// No implicit/demo publisher key is trusted. Rotation/revocation requires the
// authorized host administrator to replace these pinned values.
return [
    'publisher_key_id' => env('FOODEX_UPDATE_PUBLISHER_KEY_ID', ''),
    'publisher_public_key_b64' => env('FOODEX_UPDATE_PUBLISHER_PUBLIC_KEY_B64', ''),
];
