<?php

namespace App\Domain\Installer;

use InvalidArgumentException;

final class InstallerChecklist
{
    /**
     * @var array<int, array{slug: string, title: string, description: string}>
     */
    private const STEPS = [
        1 => ['slug' => 'welcome', 'title' => 'Welcome', 'description' => 'Start the secured FOODEX first-run setup.'],
        2 => ['slug' => 'requirements', 'title' => 'Server Requirements', 'description' => 'Verify PHP and required extensions.'],
        3 => ['slug' => 'permissions', 'title' => 'File/Folder Permissions', 'description' => 'Verify runtime directories are writable.'],
        4 => ['slug' => 'database', 'title' => 'Database Configuration', 'description' => 'Configure the PostgreSQL connection without logging credentials.'],
        5 => ['slug' => 'database-test', 'title' => 'Test Database Connection', 'description' => 'Verify the configured database is reachable.'],
        6 => ['slug' => 'platform', 'title' => 'Platform Information', 'description' => 'Set the platform name, URL and primary locale.'],
        7 => ['slug' => 'super-admin', 'title' => 'Create Super Admin', 'description' => 'Prepare the first platform owner account.'],
        8 => ['slug' => 'storage', 'title' => 'Storage Configuration', 'description' => 'Choose and verify the application storage disk.'],
        9 => ['slug' => 'cache-queue', 'title' => 'Cache/Queue Configuration', 'description' => 'Configure Redis-backed cache and queues.'],
        10 => ['slug' => 'mail', 'title' => 'Mail Configuration', 'description' => 'Configure outbound mail transport.'],
        11 => ['slug' => 'migrations', 'title' => 'Run Migrations', 'description' => 'Create and upgrade the approved database schema.'],
        12 => ['slug' => 'seed', 'title' => 'Seed Required Core Data', 'description' => 'Seed deterministic roles, permissions and reference data.'],
        13 => ['slug' => 'keys', 'title' => 'Generate Application Keys', 'description' => 'Generate a fresh application encryption key.'],
        14 => ['slug' => 'health', 'title' => 'Health Check', 'description' => 'Verify database, storage, cache and queue configuration.'],
        15 => ['slug' => 'finish', 'title' => 'Finish', 'description' => 'Commit the Super Admin, installed version and installer lock.'],
    ];

    /**
     * @return array<int, string>
     */
    public static function steps(): array
    {
        return array_values(array_map(
            static fn (array $step): string => $step['title'],
            self::STEPS,
        ));
    }

    /**
     * @return array<int, array{slug: string, title: string, description: string}>
     */
    public static function details(): array
    {
        return self::STEPS;
    }

    /**
     * @return array{slug: string, title: string, description: string}
     */
    public static function step(int $number): array
    {
        if (! isset(self::STEPS[$number])) {
            throw new InvalidArgumentException('Unknown installer step.');
        }

        return self::STEPS[$number];
    }

    public static function count(): int
    {
        return count(self::STEPS);
    }
}
