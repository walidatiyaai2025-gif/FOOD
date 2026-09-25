<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ManagementReportService
{
    public function orders(User $user, array $filters): array
    {
        $query = DB::table('orders')->selectRaw('status, channel, COUNT(*) as orders_count, COALESCE(SUM(grand_total),0) as revenue')->groupBy('status','channel');
        if (! empty($filters['from'])) $query->whereDate('created_at','>=',$filters['from']);
        if (! empty($filters['to'])) $query->whereDate('created_at','<=',$filters['to']);
        if (! empty($filters['store_id'])) $query->where('store_id',$filters['store_id']);
        if (! empty($filters['channel'])) $query->where('channel',$filters['channel']);
        $rows = $query->get();
        return ['rows'=>$rows,'kpis'=>['orders'=>(int)$rows->sum('orders_count'),'revenue'=>(float)$rows->sum('revenue')]];
    }

    public function products(array $filters): array
    {
        $query = DB::table('order_items')->join('orders','orders.id','=','order_items.order_id')->selectRaw('order_items.product_id, order_items.sku_snapshot as sku, order_items.name_snapshot as name, SUM(order_items.quantity) as quantity, SUM(order_items.line_total) as revenue')->groupBy('order_items.product_id','order_items.sku_snapshot','order_items.name_snapshot');
        if (! empty($filters['from'])) $query->whereDate('orders.created_at','>=',$filters['from']);
        if (! empty($filters['to'])) $query->whereDate('orders.created_at','<=',$filters['to']);
        if (! empty($filters['store_id'])) $query->where('orders.store_id',$filters['store_id']);
        if (! empty($filters['channel'])) $query->where('orders.channel',$filters['channel']);
        $rows=$query->orderByDesc('quantity')->get();
        return ['rows'=>$rows,'best'=>$rows->first(),'least'=>$rows->sortBy('quantity')->first()];
    }
}
