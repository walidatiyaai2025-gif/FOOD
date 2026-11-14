<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallerWorkflow;
use Tests\TestCase;

final class V2InstallerExtensionPreflightTest extends TestCase
{
    public function test_web_installer_requires_php_extensions_needed_by_update_zip_and_uploads(): void
    {
        $this->assertSame(
            ['pdo', 'openssl', 'mbstring', 'tokenizer', 'json', 'zip', 'fileinfo'],
            InstallerWorkflow::REQUIRED_EXTENSIONS,
        );
    }

    public function test_missing_zip_and_fileinfo_fail_requirements_in_both_locales(): void
    {
        $workflow = app(InstallerWorkflow::class);
        $originalLocale = app()->getLocale();

        try {
            foreach (['en', 'ar'] as $locale) {
                app()->setLocale($locale);

                $failures = $workflow->serverRequirementFailures(
                    static fn (string $extension): bool => ! in_array($extension, ['zip', 'fileinfo'], true),
                );

                $this->assertSame([
                    (string) __('installer.errors.extension', ['extension' => 'zip']),
                    (string) __('installer.errors.extension', ['extension' => 'fileinfo']),
                ], $failures, "Unexpected extension validation for {$locale}");
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_valid_extensions_pass_and_mysql_requirement_retains_its_specialized_error(): void
    {
        $workflow = app(InstallerWorkflow::class);

        $this->assertSame(
            [],
            $workflow->serverRequirementFailures(static fn (string $extension): bool => true),
        );

        $this->assertSame(
            [(string) __('installer.errors.pdo_mysql')],
            $workflow->serverRequirementFailures(
                static fn (string $extension): bool => $extension !== 'pdo_mysql',
            ),
        );
    }
}
