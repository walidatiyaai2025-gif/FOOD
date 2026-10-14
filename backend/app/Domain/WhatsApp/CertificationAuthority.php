<?php

namespace App\Domain\WhatsApp;

/**
 * Implemented by #1407 only. The authority must validate durable, canonical
 * evidence for the complete program and return null for absent/revoked evidence.
 * Neither HTTP input, ordinary settings, nor a lifecycle value is certification.
 */
interface CertificationAuthority
{
    public function validEvidenceReference(): ?string;
}
