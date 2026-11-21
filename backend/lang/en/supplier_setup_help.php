<?php

return [
    'code_example' => 'SUP-EXAMPLE',
    'readiness' => 'Setup readiness: unique supplier code, English name and an authorized company/store scope are required. Legal registration fields are optional in current validation and are not certified without genuine documents.',
    'scope_help' => 'Choose Store for a supplier serving the selected store, or Company for a supplier shared within the authorized company. Source: supplier contract and approved organization hierarchy. This form cannot switch companies.',
    'code_help' => 'Enter the approved internal reference from your supplier register; fictional example: SUP-EXAMPLE. Latin letters, digits, dot, dash and underscore only, maximum 64 characters.',
    'name_help' => 'Use the legal or trading name from approved supplier documents. English name is required; Arabic name is optional in current validation.',
    'tax_help' => 'Obtain tax and commercial registration numbers from official supplier documents. Leave blank if not provided. Never treat sample identifiers as real registrations.',
    'terms_help' => 'Optional payment terms from 0 to 3650 days, sourced from the approved contract. Blank does not grant credit or approve commercial payment terms.',
    'test' => 'Test without saving',
    'test_passed' => 'Supplier fields, authority, scope and uniqueness passed without creating a record. This does not certify legal documents or grant an independent approval.',
    'test_caveat' => 'Testing does not create a supplier or modify records/audits. Saving requires supplier-management permission; contract and invoice approval retain separate maker/checker controls.',
];
