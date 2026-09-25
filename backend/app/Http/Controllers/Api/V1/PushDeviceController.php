<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\PushDeviceToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PushDeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user=$request->user(); abort_unless($user instanceof User,401);
        $data=$request->validate(['app'=>['required','in:customer,driver'],'platform'=>['required','in:android,ios'],'environment'=>['required','in:development,staging,production'],'token'=>['required','string','max:4096']]);
        $allowed=$data['app']==='driver'?DB::table('drivers')->where('user_id',$user->id)->exists():DB::table('customers')->where('user_id',$user->id)->exists();
        abort_unless($allowed,403);
        $device=PushDeviceToken::query()->updateOrCreate(['token_hash'=>hash('sha256',$data['token'])],[
            'user_id'=>$user->id,'app'=>$data['app'],'platform'=>$data['platform'],'environment'=>$data['environment'],'token_encrypted'=>$data['token'],'revoked_at'=>null
        ]);
        return response()->json(['data'=>['id'=>$device->id,'app'=>$device->app,'platform'=>$device->platform,'environment'=>$device->environment]],$device->wasRecentlyCreated?201:200);
    }
    public function destroy(Request $request, PushDeviceToken $device): JsonResponse
    {
        abort_unless((int)$device->user_id===(int)$request->user()?->getAuthIdentifier(),404);
        $device->update(['revoked_at'=>now()]);
        return response()->json(['status'=>'revoked']);
    }
}
