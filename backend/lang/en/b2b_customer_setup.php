<?php

return [
    'title' => 'Wholesale customer master data setup',
    'purpose' => 'Create a Wholesale identity and business account using approved, real customer details. The new account awaits approval and receives no automatic credit or price tier.',
    'readiness' => 'Setup readiness',
    'status' => 'Needs approval: even valid fields do not authorize the account to transact until independent review and activation.',
    'help' => 'Purpose, data source, validation and permissions',
    'source' => 'Source: obtain the customer’s official company name and verified account contact/email from Sales or Account Management; use a tax registration only if applicable.',
    'example' => 'Fictional example only: Example Trading Company, contact Example Customer, email customer@example.invalid. Never use this sample in production.',
    'default' => 'Safe default: no invented identity or credit. Save creates a pending account with inactive login; no automatic credit or price-tier approval.',
    'validation' => 'Validation: company, account contact, unique email and temporary password (at least 8 characters) are required. Phone is optional up to 50 characters; tax number optional up to 100. Save revalidates.',
    'permissions' => 'Authorization: an approved Wholesale user with b2b.accounts.manage. Retail roles cannot test or create; account activation, finance credit and pricing approvals remain separate.',
    'test_help' => 'Server Test checks form shape and email uniqueness without creating a user, account, ledger entry or audit record. It does not verify legal identity or approve credit.',
    'company' => 'Approved customer company name *',
    'contact' => 'Account contact name *',
    'email' => 'Unique contact email *',
    'phone' => 'Phone (optional)',
    'tax' => 'Tax registration number if applicable (optional)',
    'password' => 'Secure temporary password *',
    'test' => 'Test without saving',
    'save' => 'Create pending customer',
    'test_valid' => 'Form precheck passed without saving. No account, login or credit was created. Save will validate again and leave the account pending approval.',
];
