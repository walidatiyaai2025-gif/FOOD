<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminValidationAccessibilityContractTest extends TestCase
{
    public function test_shared_validation_runtime_preserves_keyed_errors_and_nested_field_relationships(): void
    {
        $brand = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));

        $this->assertIsString($brand);
        $this->assertStringContainsString('$errors->messages()', $brand);
        $this->assertStringContainsString('window.FoodexValidationA11y = {', $brand);
        $this->assertStringContainsString('normalizeValidationKey', $brand);
        $this->assertStringContainsString('wildcardValidationKey', $brand);
        $this->assertStringContainsString('controlsForValidationKey', $brand);
        $this->assertStringContainsString("field.setAttribute('aria-invalid','true')", $brand);
        $this->assertStringContainsString("field.setAttribute('aria-errormessage', errorId)", $brand);
        $this->assertStringContainsString('addDescribedBy(field, errorId)', $brand);
        $this->assertStringContainsString("field.addEventListener('input', clear)", $brand);
        $this->assertStringContainsString("field.addEventListener('change', clear)", $brand);
        $this->assertStringContainsString('data-foodex-validation-link', $brand);
        $this->assertStringContainsString('const close = ({ restoreFocus = true } = {}) => {', $brand);
        $this->assertStringContainsString('close({ restoreFocus: false });', $brand);
        $this->assertStringContainsString('modalSession?.deactivate({ restoreFocus });', $brand);
        $this->assertStringContainsString('target.focus({ preventScroll:false })', $brand);
    }

    public function test_json_validation_responses_keep_error_keys_instead_of_flattening_before_feedback(): void
    {
        $brand = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));

        $this->assertIsString($brand);
        $this->assertStringContainsString('responseErrors = payload.errors;', $brand);
        $this->assertStringContainsString("showFeedback('error', hasFeedbackErrors(responseErrors) ? responseErrors", $brand);
        $this->assertStringContainsString("if (document.body.dataset.foodexValidationSummaryNative === '1') applyServerValidation(feedback.errors);", $brand);
    }

    public function test_login_contract_uses_stable_server_error_ids_and_native_linked_summary(): void
    {
        $login = file_get_contents(resource_path('views/admin/login.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Admin/AdminLoginController.php'));

        $this->assertIsString($login);
        $this->assertIsString($controller);
        $this->assertStringContainsString('data-foodex-validation-summary-native="1"', $login);
        $this->assertStringContainsString('data-foodex-native-error-summary', $login);
        $this->assertStringContainsString('data-foodex-error-summary-key="{{ $field }}"', $login);
        $this->assertStringContainsString('aria-describedby="foodex-server-error-email"', $login);
        $this->assertStringContainsString('aria-errormessage="foodex-server-error-email"', $login);
        $this->assertStringContainsString('aria-describedby="foodex-server-error-password"', $login);
        $this->assertStringContainsString('aria-errormessage="foodex-server-error-password"', $login);
        $this->assertStringContainsString("'email' => [__('admin_login.invalid_credentials')]", $controller);
        $this->assertStringNotContainsString("'password' => [__('admin_login.invalid_credentials')]", $controller);
    }
}
