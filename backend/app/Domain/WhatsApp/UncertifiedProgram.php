<?php

namespace App\Domain\WhatsApp;

final class UncertifiedProgram implements CertificationAuthority
{
    public function validEvidenceReference(): ?string
    {
        return null;
    }
}
