.PHONY: backend-test customer-test driver-test policy

backend-test:
	cd backend && php artisan test

customer-test:
	cd apps/customer_app && flutter test

driver-test:
	cd apps/driver_app && flutter test

policy:
	./scripts/validate-repo.sh
