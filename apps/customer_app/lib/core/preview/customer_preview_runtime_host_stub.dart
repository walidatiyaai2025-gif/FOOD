import 'customer_preview_bridge_contract.dart';

bool get isEmbeddedCustomerPreviewRuntime => false;

Future<CustomerPreviewBootstrap?> waitForCustomerPreviewBootstrap() async => null;

void postCustomerPreviewStatus(String state, {String? code}) {}
