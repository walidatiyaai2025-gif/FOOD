<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ManagementReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ManagementReportController extends Controller
{
    public function __construct(private ManagementReportService $reports) {}

    public function orders(Request $request): JsonResponse
    {
        Gate::authorize('reports.view');
        $filters=$request->validate(['from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from'],'store_id'=>['nullable','integer'],'channel'=>['nullable','in:b2b,b2c']]);
        return response()->json(['data'=>$this->reports->orders($request->user(),$filters),'filters'=>$filters]);
    }

    public function products(Request $request): JsonResponse
    {
        Gate::authorize('reports.view');
        $filters=$request->validate(['from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from'],'store_id'=>['nullable','integer'],'channel'=>['nullable','in:b2b,b2c']]);
        return response()->json(['data'=>$this->reports->products($filters),'filters'=>$filters]);
    }
}
