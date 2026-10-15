<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Smart Ordering — Appearance</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#0b1020;color:#eef2ff;font-family:Inter,ui-sans-serif,system-ui,sans-serif}.builder{min-height:100vh;display:grid;grid-template-columns:minmax(280px,360px) 1fr}.panel{padding:28px;border-inline-end:1px solid #29314b;background:#11182b;overflow:auto}.panel h1{font-size:22px;margin:0 0 6px}.panel p{color:#aab4cf;line-height:1.5}.group{margin:22px 0}.group label{display:block;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#97a4c4;margin-bottom:8px}.group select,.group input{width:100%;border:1px solid #36415f;background:#0b1020;color:#fff;border-radius:12px;padding:12px}.templates{display:grid;gap:8px}.templates button{border:1px solid #36415f;background:#151e35;color:#fff;border-radius:14px;padding:12px;text-align:start;cursor:pointer}.templates button[aria-pressed=true]{border-color:#f8b84e;box-shadow:0 0 0 1px #f8b84e}.templates strong,.templates span{display:block}.templates span{font-size:12px;color:#9aa8c7;margin-top:3px}.segmented{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}.segmented button{padding:10px;border:1px solid #36415f;background:#151e35;color:#fff;border-radius:10px}.segmented button[aria-pressed=true]{background:#f8b84e;color:#17100a;border-color:#f8b84e}.stage{padding:28px;display:grid;place-items:center;overflow:auto}.device{background:#070b14;border:1px solid #29314b;border-radius:28px;padding:12px;box-shadow:0 30px 80px #0008;transition:width .2s ease;max-width:100%}.device[data-device=mobile]{width:390px}.device[data-device=tablet]{width:820px}.device[data-device=desktop]{width:1280px}.device iframe{display:block;width:100%;height:82vh;border:0;border-radius:18px;background:#fff}.empty{padding:40px;color:#aab4cf;text-align:center}.hint{font-size:12px;color:#7f8aa7;margin-top:18px}@media(max-width:900px){.builder{grid-template-columns:1fr}.panel{border-inline-end:0;border-bottom:1px solid #29314b}.stage{padding:16px}.device iframe{height:70vh}}
</style>
</head>
<body>
<div class="builder" data-builder>
<aside class="panel">
<h1>Storefront appearance</h1><p>Controlled theme customization. Switching templates never edits menu or site content.</p>
<div class="group"><label for="site">Restaurant public key</label><input id="site" value="{{ $siteKey }}" placeholder="restaurant-public-key" autocomplete="off"></div>
<div class="group"><label>Template</label><div class="templates">@foreach($themes as $id => $definition)<button type="button" data-theme="{{ $id }}" aria-pressed="{{ $selectedTheme === $id ? 'true' : 'false' }}"><strong>{{ $definition['label'] }}</strong><span>{{ $definition['layout'] }}</span></button>@endforeach</div></div>
<div class="group"><label>Appearance</label><div class="segmented"><button type="button" data-appearance="light" aria-pressed="{{ $selectedAppearance === 'light' ? 'true' : 'false' }}">Light</button><button type="button" data-appearance="dark" aria-pressed="{{ $selectedAppearance === 'dark' ? 'true' : 'false' }}">Dark</button></div></div>
<div class="group"><label>Language</label><div class="segmented"><button type="button" data-lang="en" aria-pressed="{{ $selectedLocale === 'en' ? 'true' : 'false' }}">EN</button><button type="button" data-lang="ar" aria-pressed="{{ $selectedLocale === 'ar' ? 'true' : 'false' }}">AR</button></div></div>
<div class="group"><label>Revision</label><select data-revision><option value="draft" @selected($selectedRevision === 'draft')>Draft appearance</option><option value="published" @selected($selectedRevision === 'published')>Published appearance</option></select></div>
<div class="group"><label>Preview size</label><div class="segmented"><button type="button" data-device="mobile" aria-pressed="{{ $selectedDevice === 'mobile' ? 'true' : 'false' }}">Mobile</button><button type="button" data-device="tablet" aria-pressed="{{ $selectedDevice === 'tablet' ? 'true' : 'false' }}">Tablet</button><button type="button" data-device="desktop" aria-pressed="{{ $selectedDevice === 'desktop' ? 'true' : 'false' }}">Desktop</button></div></div>
<p class="hint">Preview responses are private, no-store and noindex. Public pages always use the published appearance.</p>
</aside>
<main class="stage"><div class="device" data-device-frame data-device="{{ $selectedDevice }}">
@if($siteKey !== '')<iframe title="Smart Ordering live preview" data-preview src="{{ route('admin.smart-ordering.appearance.preview', ['site' => $siteKey, 'theme' => $selectedTheme, 'appearance' => $selectedAppearance, 'lang' => $selectedLocale, 'revision' => $selectedRevision]) }}"></iframe>
@else<div class="empty" data-empty>Enter a restaurant public key to start the live preview.</div><iframe title="Smart Ordering live preview" data-preview hidden></iframe>@endif
</div></main>
</div>
<script>
(() => {
const root=document.querySelector('[data-builder]'),site=document.getElementById('site'),frame=root.querySelector('[data-preview]'),empty=root.querySelector('[data-empty]'),deviceFrame=root.querySelector('[data-device-frame]');
const state={theme:@json($selectedTheme),appearance:@json($selectedAppearance),lang:@json($selectedLocale),revision:@json($selectedRevision),device:@json($selectedDevice)};
const setPressed=(selector,key,value)=>root.querySelectorAll(selector).forEach(button=>button.setAttribute('aria-pressed',button.dataset[key]===value?'true':'false'));
const refresh=()=>{const key=site.value.trim();deviceFrame.dataset.device=state.device;if(!key){frame.hidden=true;if(empty)empty.hidden=false;return;}const params=new URLSearchParams({site:key,theme:state.theme,appearance:state.appearance,lang:state.lang,revision:state.revision});frame.src=@json(route('admin.smart-ordering.appearance.preview'))+'?'+params.toString();frame.hidden=false;if(empty)empty.hidden=true;};
root.addEventListener('click',event=>{const button=event.target.closest('button');if(!button)return;if(button.dataset.theme){state.theme=button.dataset.theme;setPressed('[data-theme]','theme',state.theme);refresh();}if(button.dataset.appearance){state.appearance=button.dataset.appearance;setPressed('[data-appearance]','appearance',state.appearance);refresh();}if(button.dataset.lang){state.lang=button.dataset.lang;setPressed('[data-lang]','lang',state.lang);refresh();}if(button.dataset.device){state.device=button.dataset.device;setPressed('[data-device]','device',state.device);refresh();}});
root.querySelector('[data-revision]').addEventListener('change',event=>{state.revision=event.target.value;refresh();});site.addEventListener('change',refresh);
})();
</script>
</body></html>
