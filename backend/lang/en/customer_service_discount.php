<?php

return [
    'not_allowed' => 'A discount is allowed only at the pending Customer Service stage before dispatch and before final payment/invoicing.',
    'tax_requires_requote' => 'Taxed orders require authoritative repricing before changing their discount.',
    'invalid_totals' => 'Unable to calculate a safe discount; review the order totals.',
    'write_off_approval_required' => 'Customer Service cannot reduce a positive-value order to zero without independent full write-off approval.',
    'label' => 'Customer Service discount',
    'percentage' => 'Discount percentage (%)',
    'reason' => 'Discount reason (required)',
    'apply' => 'Apply discount and notify customer',
    'note_placeholder' => 'Reason for the discount shown to the customer',
    'request' => 'Submit discount for review',
    'request_submitted' => 'Discount request submitted for review. Order totals remain unchanged.',
    'request_duplicate' => 'This request was already submitted; totals remain unchanged.',
    'pending_review' => 'Customer Service discounts awaiting review',
    'requester' => 'Requested by',
    'approve' => 'Approve and apply discount',
    'reject' => 'Reject request',
    'review_reason' => 'Reason for rejection (required)',
    'request_approved' => 'Discount reviewed and applied with an audit trail.',
    'request_rejected' => 'Discount request rejected without changing totals.',
];
