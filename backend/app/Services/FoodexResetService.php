<?php

namespace App\Services;

use App\Models\User;
use Database\Seeders\FoodexLaunchSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

final class FoodexResetService
{
    public const CONFIRMATION = 'RESET FOODEX DATA';

    public const PRODUCTION_CONFIRMATION = 'RESET FOODEX PRODUCTION';

    /**
     * Child-first deletion order. Security identity, RBAC, provider credentials,
     * stores and platform configuration are intentionally not reset here.
     *
     * @var list<string>
     */
    private const RESET_TABLES = [
        'delivery_proofs',
        'driver_assignments',
        'payments',
        'order_status_history',
        'order_items',
        'orders',
        'cart_items',
        'carts',
        'notifications',
        'stock_movements',
        'inventories',
        'coupons',
        'promotions',
        'banners',
        'product_images',
        'product_selling_units',
        'store_products',
        'products',
        'categories',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{
     *   environment:string,
     *   seed_ready:bool,
     *   confirmation:string,
     *   production_confirmation:string,
     *   requires_backup:bool,
     *   tables:array<string,int>,
     *   total_rows:int
     * }
     */
    public function preview(): array
    {
        $tables = [];
        foreach (self::RESET_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $tables[$table] = DB::table($table)->count();
            }
        }

        return [
            'environment' => App::environment(),
            'seed_ready' => class_exists(FoodexLaunchSeeder::class),
            'confirmation' => self::CONFIRMATION,
            'production_confirmation' => self::PRODUCTION_CONFIRMATION,
            'requires_backup' => App::environment('production'),
            'tables' => $tables,
            'total_rows' => array_sum($tables),
        ];
    }

    /**
     * @param array{confirmation:string,production_confirmation?:?string,backup_reference?:?string} $input
     * @return array{before:array<string,mixed>,after:array<string,mixed>}
     */
    public function execute(User $actor, Request $request, array $input): array
    {
        abort_unless($actor->hasRole('SUPER_ADMIN'), 403);

        if (($input['confirmation'] ?? '') !== self::CONFIRMATION) {
            throw ValidationException::withMessages([
                'confirmation' => __('foodex_reset.errors.confirmation'),
            ]);
        }

        if (! class_exists(FoodexLaunchSeeder::class)) {
            throw ValidationException::withMessages([
                'confirmation' => __('foodex_reset.errors.seed_not_ready'),
            ]);
        }

        if (App::environment('production')) {
            if (($input['production_confirmation'] ?? '') !== self::PRODUCTION_CONFIRMATION) {
                throw ValidationException::withMessages([
                    'production_confirmation' => __('foodex_reset.errors.production_confirmation'),
                ]);
            }

            if (trim((string) ($input['backup_reference'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'backup_reference' => __('foodex_reset.errors.backup_required'),
                ]);
            }
        }

        $before = $this->preview();

        $this->audit->record(
            'foodex.reset_reseed.started',
            $actor,
            null,
            null,
            [
                'environment' => $before['environment'],
                'row_counts' => $before['tables'],
                'backup_reference' => $input['backup_reference'] ?? null,
            ],
            $request,
        );

        try {
            Schema::disableForeignKeyConstraints();

            DB::transaction(function (): void {
                foreach (self::RESET_TABLES as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }

                Artisan::call('db:seed', [
                    '--class' => FoodexLaunchSeeder::class,
                    '--force' => true,
                ]);
            });
        } catch (Throwable $exception) {
            $this->audit->record(
                'foodex.reset_reseed.failed',
                $actor,
                null,
                ['row_counts' => $before['tables']],
                ['error_class' => $exception::class],
                $request,
            );

            throw $exception;
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        Artisan::call('cache:clear');

        $after = $this->preview();
        $this->audit->record(
            'foodex.reset_reseed.completed',
            $actor,
            null,
            ['row_counts' => $before['tables']],
            [
                'row_counts' => $after['tables'],
                'backup_reference' => $input['backup_reference'] ?? null,
            ],
            $request,
        );

        return ['before' => $before, 'after' => $after];
    }
}
