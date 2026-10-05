const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const backend = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(backend, 'public/assets/admin/driver-live-map.js'), 'utf8');
const loader = fs.readFileSync(path.join(backend, 'resources/views/admin/_driver-live-map-scripts.blade.php'), 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1];
const i18n = {
    loading:'Loading', ready:'Live', noDrivers:'No drivers', failed:'Failed', assetsFailed:'Assets failed',
    mapFailed:'Map failed', sessionExpired:'Sign in again', forbidden:'Permission denied',
    serverFailed:'Server unavailable', networkFailed:'Network unavailable', invalidFeed:'Invalid response', timeout:'Timed out',
};
class Node {
    constructor() { this.textContent=''; this.hidden=false; this.children=[]; this.listeners={}; this.style={}; this.value=''; this.isConnected=true; }
    append(...nodes) { this.children.push(...nodes); }
    replaceChildren(...nodes) { this.children=nodes; }
    addEventListener(type, callback) { this.listeners[type]=callback; }
    click() { this.listeners.click?.({preventDefault() {}}); }
}
function root(mode) {
    const node = new Node();
    node.dataset={feedUrl:'/admin/driver-live-tracking/feed?channel=b2b&store_id=7',pollMs:'5000',mode,assetsFailed:i18n.assetsFailed};
    node.parts = Object.fromEntries(['map','state','error','error-message','updated','retry','count-online','count-stale','count-offline',
        ...(mode === 'full' ? ['list','search','apply','clear','recenter','channel','store','status-filter','driver-id','order-id'] : [])].map(role=>[role,new Node()]));
    node.parts.updated.textContent='—';
    node.parts.error.hidden=true;
    const translations = {textContent:JSON.stringify(i18n)};
    node.querySelector = selector => selector === '[data-driver-live-map-i18n]' ? translations : node.parts[selector.match(/"(.*)"/)?.[1]] || null;
    return node;
}
function setup({mode='full', noLeaflet=false, brokenMap=false, roots:givenRoots, response}={}) {
    const roots=givenRoots || [root(mode)];
    const calls=[], timers=new Map(), intervals=[], events={};
    let nextTimer=0;
    const layer={clearCount:0,addTo(){return this;},clearLayers(){this.clearCount++;}};
    const map={removeCount:0,setView(){return this;},fitBounds(){},remove(){this.removeCount++;}};
    const leaflet={map(){if(brokenMap) throw Error('init failed');return map;},tileLayer(){return {addTo(){}};},layerGroup(){return layer;},circleMarker(){return {bindPopup(){return this;},addTo(){return this;},openPopup(){}};}};
    const window={L:noLeaflet?undefined:leaflet,location:{origin:'https://dashboard.example'},
        setTimeout(fn,ms){const id=++nextTimer;timers.set(id,{fn,ms});return id;},clearTimeout(id){timers.delete(id);},
        setInterval(fn){intervals.push(fn);},addEventListener(type,fn){events[type]=fn;}};
    const context=vm.createContext({window,L:window.L,URL,AbortController,document:{querySelectorAll(){return roots;},createElement(){return new Node();},createTextNode(value){return {textContent:value};}},
        fetch:async(url,options)=>{calls.push({url,options});return response ? response(url,options) : {ok:true,json:async()=>({data:[],meta:{generated_at:'2026-10-01T05:00:00Z'}})};}});
    return {roots,calls,timers,intervals,events,layer,map,context,run(){vm.runInContext(source,context);},watchdog(){vm.runInContext(loader,context);}};
}
const settle = () => new Promise(resolve=>setImmediate(resolve));
for (const mode of ['full','compact']) {
    test(`${mode}: missing Leaflet is an explicit asset error, not an empty feed`,()=>{
        const env=setup({mode,noLeaflet:true});env.run();
        assert.equal(env.roots[0].dataset.liveMapError,'map-assets');
        assert.equal(env.roots[0].parts.error.hidden,false);
        assert.equal(env.roots[0].parts.state.textContent,i18n.assetsFailed);
        assert.equal(env.roots[0].parts['count-online'].textContent,'—');
        assert.equal(env.roots[0].parts.updated.textContent,'—');assert.equal(env.calls.length,0);
    });
    test(`${mode}: successful empty feed advances timestamp and clears errors`,async()=>{
        const env=setup({mode});env.run();await settle();
        const view=env.roots[0];
        assert.equal(view.parts.state.textContent,i18n.noDrivers);
        assert.notEqual(view.parts.updated.textContent,'—');assert.equal(view.parts.error.hidden,true);
        assert.equal(view.parts['count-online'].textContent,'0');assert.equal(view.dataset.liveMapError,undefined);
        assert.equal(new URL(env.calls[0].url).searchParams.get('store_id'),'7');
        assert.equal(new URL(env.calls[0].url).searchParams.get('channel'),'b2b');
        assert.equal(env.calls[0].options.credentials,'same-origin');
        assert.equal(env.intervals.length,0);
        assert.equal([...env.timers.values()].filter(timer=>timer.ms===5000).length,1);
    });
}
test('map initialization exception is visible and does not prevent the next root starting',async()=>{
    const env=setup({brokenMap:true});env.run();assert.equal(env.roots[0].dataset.liveMapError,'map-initialization');assert.equal(env.calls.length,0);
    const first=root('full'),second=root('compact');delete first.parts.map;
    const multiple=setup({roots:[first,second]});multiple.run();await settle();
    assert.equal(first.dataset.liveMapError,'map-configuration');assert.equal(second.dataset.driverLiveMapReady,'1');assert.equal(multiple.calls.length,1);
});
for(const [status,code,message] of [[401,'feed-401','sessionExpired'],[403,'feed-403','forbidden'],[503,'feed-maintenance','serverFailed']]) {
    test(`feed ${status} has a distinct error and retry recovers`,async()=>{
        let failing=true;
        const env=setup({response:async()=> failing ? {ok:false,status} : {ok:true,json:async()=>({data:[]})}});
        env.run();await settle();const view=env.roots[0];
        assert.equal(view.dataset.liveMapError,code);assert.equal(view.parts['error-message'].textContent,i18n[message]);
        assert.equal(view.parts.updated.textContent,'—');failing=false;
        if(status === 401) {
            let prevented=false;view.parts.retry.listeners.click({preventDefault(){prevented=true;}});
            assert.equal(prevented,false, 'expired session permits a real page reload');
            await view.foodexDriverLiveMap.refresh();
        } else {view.parts.retry.click();await settle();}
        assert.equal(view.dataset.liveMapError,undefined);assert.equal(view.parts.error.hidden,true);assert.notEqual(view.parts.updated.textContent,'—');
    });
}
for(const payload of [null,{}, {data:null}, {data:[null]}]) test(`invalid payload ${JSON.stringify(payload)} cannot masquerade as zero drivers`,async()=>{
    const env=setup({response:async()=>({ok:true,json:async()=>payload})});env.run();await settle();
    assert.equal(env.roots[0].dataset.liveMapError,'feed-invalid');assert.equal(env.roots[0].parts.updated.textContent,'—');
});
test('malformed JSON, session redirect and network failure are classified',async()=>{
    for(const [response,code] of [
        [async()=>({ok:true,json:async()=>{throw Error('bad JSON');}}),'feed-invalid'],
        [async()=>({ok:true,redirected:true}),'feed-session'],
        [async()=>{throw Error('offline');},'feed-network'],
    ]) {const env=setup({response});env.run();await settle();assert.equal(env.roots[0].dataset.liveMapError,code);}
});
test('timeout releases the in-flight guard so retry and polling can recover',async()=>{
    let hanging=true;
    const env=setup({response:async(_,options)=>hanging ? new Promise((_,reject)=>options.signal.addEventListener('abort',()=>reject(Error('aborted')))) : {ok:true,json:async()=>({data:[]})}});
    env.run();assert.equal(env.calls.length,1);
    await env.roots[0].foodexDriverLiveMap.refresh();assert.equal(env.calls.length,1);
    [...env.timers.values()].find(timer=>timer.ms===10000).fn();await settle();
    assert.equal(env.roots[0].dataset.liveMapError,'feed-timeout');hanging=false;
    await env.roots[0].foodexDriverLiveMap.refresh();assert.equal(env.roots[0].dataset.liveMapError,undefined);
});
test('background feed failure preserves the last successful map, counters and timestamp',async()=>{
    let failing=false;
    const env=setup({response:async()=>failing ? {ok:false,status:503} : {ok:true,json:async()=>({data:[{driver_id:1,latitude:29,longitude:48,status:'online'}]})}});
    env.run();await settle();const view=env.roots[0];const updated=view.parts.updated.textContent;
    const clearCount=env.layer.clearCount;
    assert.equal(view.parts['count-online'].textContent,'1');
    failing=true;await view.foodexDriverLiveMap.refresh();
    assert.equal(view.foodexDriverLiveMap.rows().length,1);
    assert.equal(view.parts['count-online'].textContent,'1');
    assert.equal(view.parts.updated.textContent,updated);
    assert.equal(env.layer.clearCount,clearCount);
    assert.equal(view.parts.error.hidden,true);
    assert.equal(view.parts.state.textContent,i18n.serverFailed);

    failing=false;
    await view.foodexDriverLiveMap.refresh();
    assert.equal(view.dataset.liveMapError,undefined);
    assert.equal(view.parts.error.hidden,true);
    assert.equal(view.parts.state.textContent,i18n.ready);
    assert.equal(view.parts['count-online'].textContent,'1');
    assert.ok(env.layer.clearCount > clearCount, 'successful recovery replaces the retained marker layer once');
});
test('background refresh is silent and overlapping refresh work is suppressed',async()=>{
    let call=0, release;
    const env=setup({response:async()=>{
        call++;
        if(call===1) return {ok:true,json:async()=>({data:[{driver_id:1,latitude:29,longitude:48,status:'online'}]})};
        return new Promise(resolve=>{release=()=>resolve({ok:true,json:async()=>({data:[{driver_id:1,latitude:29.1,longitude:48.1,status:'online'}]})});});
    }});
    env.run();await settle();const view=env.roots[0];
    assert.equal(view.parts.state.textContent,i18n.ready);
    const pending=view.foodexDriverLiveMap.refresh();
    assert.equal(view.parts.state.textContent,i18n.ready);
    await view.foodexDriverLiveMap.refresh();
    assert.equal(env.calls.length,2);
    release();await pending;
    assert.equal(view.parts.state.textContent,i18n.ready);
});
test('map polling and Leaflet runtime are disposed when the page leaves',async()=>{
    const env=setup();env.run();await settle();const view=env.roots[0];
    assert.equal([...env.timers.values()].filter(timer=>timer.ms===5000).length,1);
    env.events.pagehide();
    assert.equal([...env.timers.values()].filter(timer=>timer.ms===5000).length,0);
    assert.equal(env.map.removeCount,1);
    const calls=env.calls.length;
    await view.foodexDriverLiveMap.refresh();
    assert.equal(env.calls.length,calls);
});
test('missing or stalled renderer is reported by independent watchdog; healthy runtime is untouched',async()=>{
    for(const event of ['load','timeout']) {
        const env=setup();env.watchdog();
        if(event==='load') env.events.load();else [...env.timers.values()].find(timer=>timer.ms===15000).fn();
        assert.equal(env.roots[0].dataset.liveMapError,'map-assets');assert.equal(env.roots[0].parts.error.hidden,false);
    }
    const env=setup();env.watchdog();env.run();await settle();env.events.load();assert.equal(env.roots[0].dataset.liveMapError,undefined);
});
test('renderer inclusion is idempotent',async()=>{const env=setup();env.run();env.run();await settle();assert.equal(env.calls.length,1);assert.equal(env.intervals.length,0);assert.equal([...env.timers.values()].filter(timer=>timer.ms===5000).length,1);});
test('local production map JS/CSS and Leaflet image dependencies are present, not HTML error pages',()=>{
    for(const [asset,signature] of [
        ['assets/leaflet/1.9.4/leaflet.js','1.9.4'],['assets/leaflet/1.9.4/leaflet.css','.leaflet-pane'],
        ['assets/admin/driver-live-map.js','data-driver-live-map'],['assets/admin/driver-live-map.css','.driver-live-map-canvas'],
    ]) {
        const bytes=fs.readFileSync(path.join(backend,'public',asset),'utf8');
        assert.ok(bytes.includes(signature),asset);assert.ok(!/^\s*(<!doctype|<html)/i.test(bytes),asset);
        if(asset.endsWith('.js')) new vm.Script(bytes);
        else for(const match of bytes.matchAll(/url\(['"]?(images\/[^)'"\s]+)['"]?\)/g)) {
            assert.ok(fs.statSync(path.join(backend,'public',path.dirname(asset),match[1])).size>0,match[1]);
        }
    }
});
