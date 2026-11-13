import '../wallet/van_wallet_contract.dart';
import 'van_order_contract.dart';

/// Project the authoritative assigned customer list into the B2B-only
/// Van sales workflow. Retail/Driver orders must never be offered here.
List<VanCustomerScope> syncWholesaleDirectSaleDraft(
  VanOrderDraftController draft,
  Iterable<VanCustomerScope> assigned,
) {
  final eligible = assigned
      .where((customer) => customer.type == 'b2b')
      .toList(growable: false);
  final selected = draft.customer;

  // Prevent a previous Retail selection (or a revoked B2B assignment)
  // from keeping cart items or quote/checkout state on this screen.
  if (selected != null &&
      !eligible.any(
        (customer) =>
            customer.type == selected.type &&
            customer.id == selected.id &&
            customer.storeId == selected.storeId,
      )) {
    draft.reset();
  }

  if (draft.customer == null && eligible.isNotEmpty) {
    draft.selectCustomer(eligible.first);
  }

  return eligible;
}
