<?php

namespace App\Enterprise\Modules;

use InvalidArgumentException;

final readonly class ModuleManifest
{
    /**
     * @param list<string>         $requiredCore
     * @param list<string>         $hardDependencies
     * @param list<string>         $optionalIntegrations
     * @param list<string>         $permissions
     * @param list<string>         $healthChecks
     * @param array<string, mixed> $navigation
     */
    public function __construct(
        public string $code,
        public int $version,
        public string $category,
        public ModuleState $defaultState,
        public array $requiredCore,
        public array $hardDependencies,
        public array $optionalIntegrations,
        public array $permissions,
        public array $healthChecks,
        public array $navigation,
    ) {
        if ($this->code === '' || preg_match('/^[a-z][a-z0-9_]*$/', $this->code) !== 1) {
            throw new InvalidArgumentException('Module code must be a stable lowercase identifier.');
        }

        if ($this->version < 1) {
            throw new InvalidArgumentException('Module manifest version must be >= 1.');
        }

        if (in_array($this->defaultState, [
            ModuleState::NOT_INSTALLED,
            ModuleState::INSTALLED,
            ModuleState::DISABLED,
        ], true) === false) {
            throw new InvalidArgumentException('Module default state must be non-operational.');
        }

        if (in_array($this->code, $this->hardDependencies, true)) {
            throw new InvalidArgumentException('A module cannot hard-depend on itself.');
        }

        if (array_intersect($this->hardDependencies, $this->optionalIntegrations) !== []) {
            throw new InvalidArgumentException('Hard dependencies and optional integrations must be distinct.');
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $state = ModuleState::tryFrom((string) ($values['default_state'] ?? 'DISABLED'));
        if ($state === null) {
            throw new InvalidArgumentException('Unknown module default state.');
        }

        return new self(
            code: (string) ($values['code'] ?? ''),
            version: (int) ($values['version'] ?? 0),
            category: (string) ($values['category'] ?? 'general'),
            defaultState: $state,
            requiredCore: self::stringList($values['required_core'] ?? []),
            hardDependencies: self::stringList($values['hard_dependencies'] ?? []),
            optionalIntegrations: self::stringList($values['optional_integrations'] ?? []),
            permissions: self::stringList($values['permissions'] ?? []),
            healthChecks: self::stringList($values['health_checks'] ?? []),
            navigation: is_array($values['navigation'] ?? null) ? $values['navigation'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'version' => $this->version,
            'category' => $this->category,
            'default_state' => $this->defaultState->value,
            'required_core' => $this->requiredCore,
            'hard_dependencies' => $this->hardDependencies,
            'optional_integrations' => $this->optionalIntegrations,
            'permissions' => $this->permissions,
            'health_checks' => $this->healthChecks,
            'navigation' => $this->navigation,
        ];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_array($value) === false) {
            throw new InvalidArgumentException('Module manifest list fields must be arrays.');
        }

        $items = array_values(array_unique(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value,
        )));

        if (in_array('', $items, true)) {
            throw new InvalidArgumentException('Module manifest list values cannot be empty.');
        }

        return $items;
    }
}
