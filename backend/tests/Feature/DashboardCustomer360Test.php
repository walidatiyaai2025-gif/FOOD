<?php

namespace Tests\Feature;

use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerDomainResolver;
use App\Services\PlatformCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardCustomer360Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        $this->store('B2B', 'WHOLESALE-MAIN', 'Main Wholesale');
        config(['foodex.platform_wholesale_store_code' => 'WHOLESALE-MAIN']);
    }

    public function test_retail_scope_hides_foreign_customer_store_and_invoice(): void
    {
        $a=$this->store('B2C','RETAIL-A','Retail A');
        $b=$this->store('B2C','RETAIL-B','Retail B');
        $user=$this->customer('Scoped Customer','scoped@example.test',$a);
        app(CustomerDomainResolver::class)->b2c($user,$b);
        $foreign=$this->customer('Foreign Customer','foreign@example.test',$b);
        $pc=PlatformCustomer::query()->where('user_id',$user->id)->firstOrFail();
        $foreignPc=PlatformCustomer::query()->where('user_id',$foreign->id)->firstOrFail();
        $aDomain=(int)DB::table('b2c_customers')->where('user_id',$user->id)->where('store_id',$a)->value('id');
        $bDomain=(int)DB::table('b2c_customers')->where('user_id',$user->id)->where('store_id',$b)->value('id');
        $invA=$this->invoice($pc,$aDomain,$a,'INV-A');
        $invB=$this->invoice($pc,$bDomain,$b,'INV-B');
        $admin=$this->retailAdmin($a);

        $this->actingAs($admin)->get(route('admin.customer-360.index'))
            ->assertOk()->assertSee('scoped@example.test')->assertDontSee('foreign@example.test');
        $this->actingAs($admin)->get(route('admin.customer-360.show',['platformCustomer'=>$pc->id]))
            ->assertOk()->assertSee('Retail A')->assertSee('INV-A')->assertDontSee('Retail B')->assertDontSee('INV-B');
        $this->actingAs($admin)->get(route('admin.customer-360.show',['platformCustomer'=>$foreignPc->id]))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.invoices.show',['invoice'=>$invA]))->assertOk();
        $this->actingAs($admin)->get(route('admin.invoices.show',['invoice'=>$invB]))->assertNotFound();
    }

    public function test_super_admin_sees_exact_origin_and_all_materialized_retail_stores(): void
    {
        $a=$this->store('B2C','RETAIL-A','Retail A');
        $b=$this->store('B2C','RETAIL-B','Retail B');
        $user=$this->customer('Unified Customer','unified@example.test',$a);
        app(CustomerDomainResolver::class)->b2c($user,$b);
        $pc=PlatformCustomer::query()->where('user_id',$user->id)->firstOrFail();
        $admin=$this->globalAdmin('SUPER_ADMIN');

        $this->actingAs($admin)->get(route('admin.customer-360.show',['platformCustomer'=>$pc->id]))
            ->assertOk()->assertSee('Retail A · RETAIL-A')->assertSee('Retail B · RETAIL-B')->assertSee('Wholesale account');
    }

    public function test_b2b_admin_does_not_receive_exact_retail_origin_store(): void
    {
        $a=$this->store('B2C','RETAIL-A','Retail A');
        $user=$this->customer('Wholesale Customer','wholesale@example.test',$a);
        $pc=PlatformCustomer::query()->where('user_id',$user->id)->firstOrFail();

        $this->actingAs($this->globalAdmin('B2B_ADMIN'))
            ->get(route('admin.customer-360.show',['platformCustomer'=>$pc->id]))
            ->assertOk()->assertSee('Wholesale account')->assertDontSee('Retail A · RETAIL-A');
    }

    private function customer(string $name,string $email,int $storeId): User
    {
        return app(PlatformCustomerService::class)->register([
            'name'=>$name,'email'=>$email,'phone'=>'+201000000000',
            'password'=>'Password123!','locale'=>'en','store_id'=>$storeId,
        ]);
    }

    private function store(string $type,string $code,string $name): int
    {
        return (int)DB::table('stores')->insertGetId([
            'store_type_id'=>DB::table('store_types')->where('code',$type)->value('id'),
            'code'=>$code,'name'=>$name,'is_active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function retailAdmin(int $storeId): User
    {
        $user=User::query()->create(['name'=>'Retail Admin','email'=>"retail-{$storeId}@example.test",'password'=>'password123','locale'=>'en','is_active'=>true]);
        $role=Role::query()->where('code','B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert(['user_id'=>$user->id,'store_id'=>$storeId,'role_id'=>$role->id,'created_at'=>now(),'updated_at'=>now()]);
        return $user;
    }

    private function globalAdmin(string $roleCode): User
    {
        $user=User::query()->create(['name'=>$roleCode,'email'=>strtolower($roleCode).'@example.test','password'=>'password123','locale'=>'en','is_active'=>true]);
        $user->roles()->attach(Role::query()->where('code',$roleCode)->firstOrFail());
        return $user;
    }

    private function invoice(PlatformCustomer $pc,int $domainId,int $storeId,string $number): int
    {
        return (int)DB::table('invoices')->insertGetId([
            'store_id'=>$storeId,'customer_id'=>$pc->legacy_customer_id,'b2c_customer_id'=>$domainId,
            'platform_customer_id'=>$pc->id,'invoice_number'=>$number,'status'=>'issued','channel'=>'b2c',
            'currency'=>'EGP','subtotal'=>10,'discount_total'=>0,'delivery_total'=>0,'tax_total'=>0,'total'=>10,
            'issued_at'=>now(),'revision'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
}
