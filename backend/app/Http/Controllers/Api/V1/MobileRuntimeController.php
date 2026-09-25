<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\MobileAppSetting;
use App\Models\PushProviderSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileRuntimeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data=$request->validate(['app'=>['required','in:customer,driver'],'environment'=>['required','in:development,staging,production'],'locale'=>['nullable','in:ar,en']]);
        $setting=MobileAppSetting::query()->where('app',$data['app'])->where('environment',$data['environment'])->first();
        if ($setting===null) return response()->json(['message'=>'Mobile runtime settings are not configured.'],404);
        $locale=$data['locale']??'ar';
        $push=PushProviderSetting::query()->where('app',$setting->app)->where('environment',$setting->environment)->get()
            ->mapWithKeys(fn(PushProviderSetting $p)=>[$p->platform=>['enabled'=>(bool)$p->enabled,'provider'=>$p->provider,'default_sound'=>$p->default_sound,'default_channel'=>$p->default_channel,'default_icon'=>$p->default_icon,'default_category'=>$p->default_category]]);
        return response()->json(['data'=>[
            'app'=>$setting->app,'environment'=>$setting->environment,'display_name'=>$setting->display_name,
            'android_package_id'=>$setting->android_package_id,'ios_bundle_id'=>$setting->ios_bundle_id,
            'published_version'=>$setting->published_version,'published_build'=>$setting->published_build,
            'minimum_supported_version'=>$setting->minimum_supported_version,'recommended_version'=>$setting->recommended_version,
            'force_update'=>(bool)$setting->force_update,'maintenance_mode'=>(bool)$setting->maintenance_mode,
            'maintenance_message'=>$locale==='en'?$setting->maintenance_message_en:$setting->maintenance_message_ar,
            'google_play_url'=>$setting->google_play_url,'app_store_url'=>$setting->app_store_url,
            'privacy_url'=>$setting->privacy_url,'terms_url'=>$setting->terms_url,'support_url'=>$setting->support_url,
            'release_notes'=>$locale==='en'?$setting->release_notes_en:$setting->release_notes_ar,
            'deep_links'=>$setting->deep_link_config??[],'store_readiness'=>$setting->store_readiness??[],'push'=>$push,
        ]]);
    }
}
