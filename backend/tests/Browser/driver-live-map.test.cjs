const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const backend = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(backend, 'public/assets/admin/driver-live-map.js'), 'utf8');
const loader = fs.readFileSync(path.join(backend, 'resources/views/admin/_driver-live-map-scripts.blade.php'), 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1];
const i18n = {
    loading:'Loading', restoring:'Refreshing restored live locations', entityRemoved:'Selected entity is no longer available', ready:'Live', noDrivers:'No locations', failed:'Failed', assetsFailed:'Assets failed',
    driver:'Driver', van:'Van', route:'Route', assignment:'Assignment',
    mapFailed:'Map failed', sessionExpired:'Sign in again', forbidden:'Permission denied',
    serverFailed:'Server unavailable', networkFailed:'Network unavailable', invalidFeed:'Invalid response', timeout:'Timed out',
};
class Node {
    constructor() { this.textContent=''; this.hidden=false; this.children=[]; this.listeners={}; this.style={}; this.value=''; this.isConnected=true; this.dataset={}; this.ownerDocument=null; this.parentNode=null; }
    append(...nodes) {
        nodes.forEach(node => {
            if (node && typeof node === 'object') {
                if (node.parentNode && node.parentNode !== this) node.remove?.();
                if (node.parentNode === this) this.children=this.children.filter(child=>child!==node);
                node.parentNode=this;
                node.isConnected=true;
                node.ownerDocument ||= this.ownerDocument;
            }
            this.children.push(node);
        });
    }
    replaceChildren(...nodes) {
        this.children.forEach(node=>{if(node && typeof node === 'object') node.parentNode=null;});
        this.children=[];
        this.append(...nodes);
    }
    remove() {
        if (this.parentNode) this.parentNode.children=this.parentNode.children.filter(child=>child!==this);
        this.parentNode=null;
        this.isConnected=false;
        if (this.ownerDocument?.activeElement===this) this.ownerDocument.activeElement=null;
    }
    focus() { if (this.ownerDocument) this.ownerDocument.activeElement=this; }
    addEventListener(type, callback) { this.listeners[type]=callback; }
    click() { this.listeners.click?.({preventDefault() {}}); }
}
function root(mode, actorKind='driver') {
    const node = new Node();
    node.dataset={
        feedUrl:'/admin/driver-live-tracking/feed?channel=b2b&store_id=7',
        secondaryFeedUrl:actorKind === 'mixed' ? '/admin/field-operations/fleet-map/feed?channel=b2b&store_id=7' : '',
        actorKind,pollMs:'5000',mode,assetsFailed:i18n.assetsFailed
    };
    node.parts = Object.fromEntries(['map','state','error','error-message','updated','retry','count-online','count-stale','count-offline',
        ...(mode === 'full' ? ['list','search','apply','clear','recenter','channel','store','entity-type','status-filter','driver-id','order-id'] : [])].map(role=>[role,new Node()]));
    node.parts.updated.textContent='—';
    node.parts.error.hidden=true;
    const translations = {textContent:JSON.stringify(i18n)};
    node.querySelector = selector => selector === '[data-driver-live-map-i18n]' ? translations : node.parts[selector.match(/"(.*)"/)?.[1]] || null;
    return node;
}
function setup({mode='full', actorKind='driver', noLeaflet=false, brokenMap=false, roots:givenRoots, response}={}) {
    const roots=givenRoots || [root(mode,actorKind)];
    const calls=[], timers=new Map(), intervals=[], events={};
    let nextTimer=0;
    const layer={clearCount:0,layers:[],addTo(){return this;},clearLayers(){this.clearCount++;this.layers=[];},removeLayer(marker){this.layers=this.layers.filter(item=>item!==marker);marker.remove?.();}};
    const map={removeCount:0,invalidateCount:0,setViewCount:0,fitBoundsCount:0,setView(){this.setViewCount++;return this;},fitBounds(){this.fitBoundsCount++;},invalidateSize(){this.invalidateCount++;return this;},remove(){this.removeCount++;}};
    const makeMarker=(latlng,options={})=>{
        const eventsForMarker={};
        const popup={content:null,setContent(content){this.content=content;}};
        return {
            latlng,options,open:false,removed:false,popup,
            bindPopup(content){popup.content=content;return this;},
            getPopup(){return popup;},
            addTo(target){target.layers.push(this);return this;},
            setLatLng(value){this.latlng=value;return this;},
            setStyle(value){this.style={...(this.style||{}),...value};return this;},
            setIcon(value){this.icon=value;return this;},
            on(type,callback){eventsForMarker[type]=callback;return this;},
            openPopup(){this.open=true;eventsForMarker.popupopen?.();return this;},
            closePopup(){this.open=false;eventsForMarker.popupclose?.();return this;},
            isPopupOpen(){return this.open;},
            remove(){this.removed=true;if(this.open) this.closePopup();}
        };
    };
    const leaflet={map(){if(brokenMap) throw Error('init failed');return map;},tileLayer(){return {addTo(){}};},layerGroup(){return layer;},circleMarker(latlng,options){return makeMarker(latlng,options);}};
    const window={L:noLeaflet?undefined:leaflet,location:{origin:'https://dashboard.example'},
        setTimeout(fn,ms){const id=++nextTimer;timers.set(id,{fn,ms});return id;},clearTimeout(id){timers.delete(id);},
        setInterval(fn){intervals.push(fn);},addEventListener(type,fn){events[type]=fn;},
        removeEventListener(type,fn){if(events[type]===fn) delete events[type];}};
    const document={activeElement:null,querySelectorAll(){return roots;},createElement(){const node=new Node();node.ownerDocument=document;return node;},createTextNode(value){return {textContent:value};}};
    roots.forEach(rootNode=>{
        rootNode.ownerDocument=document;
        Object.values(rootNode.parts||{}).forEach(part=>{part.ownerDocument=document;part.parentNode=rootNode;});
    });
    const context=vm.createContext({window,L:window.L,URL,AbortController,document,
        fetch:async(url,options)=>{calls.push({url,options});return response ? response(url,options) : {ok:true,json:async()=>({data:[],meta:{generated_at:'2026-10-01T05:00:00Z'}})};}});
    return {roots,calls,timers,intervals,events,layer,map,document,context,run(){vm.runInContext(source,context);},watchdog(){vm.runInContext(loader,context);}};
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
test('mixed mode merges authoritative Driver and Van feeds on one map runtime',async()=>{
    const env=setup({
        actorKind:'mixed',
        response:async(url)=> {
            const parsed=new URL(url);
            if(parsed.pathname.includes('/field-operations/fleet-map/feed')) {
                return {ok:true,json:async()=>({data:[{
                    actor_type:'van',actor_id:5,entity_name:'Van 5',latitude:29.3,longitude:48.3,status:'online',
                    received_at:'2026-10-01T05:00:00Z',route_key:'R-5'
                }],meta:{generated_at:'2026-10-01T05:00:00Z'}})};
            }
            return {ok:true,json:async()=>({data:[{
                driver_id:4,driver_name:'Driver 4',latitude:29.2,longitude:48.2,status:'online',
                received_at:'2026-10-01T05:00:00Z',order:{number:'ORD-4'}
            }],meta:{generated_at:'2026-10-01T05:00:00Z'}})};
        }
    });
    env.run();await settle();
    assert.equal(env.calls.length,2);
    assert.deepEqual(
        Array.from(env.roots[0].foodexDriverLiveMap.rows(),row=>row.actor_type).sort(),
        ['driver','van']
    );
    assert.equal(env.roots[0].parts['count-online'].textContent,'2');
});
test('full live tracking lookup runtime is bounded, cancellable and keyboard accessible',()=> {
    assert.ok(source.includes("window.setTimeout(load, 250)"));
    assert.ok(source.includes("controller?.abort()"));
    assert.ok(source.includes("event.key === 'ArrowDown'"));
    assert.ok(source.includes("event.key === 'ArrowUp'"));
    assert.ok(source.includes("event.key === 'Enter'"));
    assert.ok(source.includes("event.key === 'Escape'"));
    assert.ok(source.includes("credentials:'same-origin'"));
    assert.ok(source.includes("url.searchParams.set('store_id', store)"));
    assert.ok(source.includes("url.searchParams.set('channel', channel)"));
});

test('selected business lookup identities drive feed parameters without exposing typed ids',()=> {
    assert.ok(source.includes("selectedEntityKind() === 'van'"));
    assert.ok(source.includes("selectedEntityKind() === 'driver'"));
    assert.ok(source.includes("order_id: inputValue('order-id')"));
    assert.ok(source.includes("route_key: inputValue('route-key')"));
    assert.ok(source.includes("lookupRegistry.get('entity')?.clear()"));
    assert.ok(source.includes("lookupRegistry.get('order')?.clear()"));
    assert.ok(source.includes("lookupRegistry.get('route')?.clear()"));
});

test('mixed runtime defines visually distinct Driver and Van marker symbols',()=>{
    assert.ok(source.includes("kind === 'van' ? '🚐' : '👤'"));
    assert.ok(source.includes("foodex-tracking-entity-marker-'+kind"));
});
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
test('poll reconciliation preserves popup, focused row, pan/zoom context and updates live content in place',async()=>{
    let generation=0;
    const env=setup({response:async()=>{
        generation++;
        return {ok:true,json:async()=>({data:[{
            driver_id:7,driver_name:'Driver 7',latitude:generation===1?29:29.2,longitude:generation===1?48:48.2,
            status:generation===1?'online':'stale',channel:'b2b',store_id:7,received_at:'2026-10-15T12:00:00Z',
            order:{number:generation===1?'ORD-7':'ORD-7-UPDATED'}
        }]})};
    }});
    env.run();
    await settle();
    const view=env.roots[0];
    const marker=env.layer.layers[0];
    const button=view.parts.list.children[0];
    marker.openPopup();
    button.focus();
    const fitCount=env.map.fitBoundsCount;

    await view.foodexDriverLiveMap.refresh();

    assert.equal(env.layer.layers[0],marker,'same Leaflet marker is reconciled');
    assert.equal(view.parts.list.children[0],button,'same list button is reconciled');
    assert.equal(env.document.activeElement,button,'focused entity action keeps focus');
    assert.equal(marker.isPopupOpen(),true,'open popup remains open');
    assert.deepEqual(Array.from(marker.latlng),[29.2,48.2]);
    assert.equal(marker.style.fillColor,'#f59e0b');
    assert.match(marker.popup.content.children[3].textContent,/ORD-7-UPDATED/);
    assert.equal(env.map.fitBoundsCount,fitCount,'background poll does not reset pan/zoom');
});

test('removed selected entity closes stale interaction, moves focus safely and announces removal',async()=>{
    let generation=0;
    const env=setup({response:async()=>{
        generation++;
        return {ok:true,json:async()=>({data:generation===1?[{
            driver_id:9,driver_name:'Driver 9',latitude:29,longitude:48,status:'online',
            channel:'b2b',store_id:7,received_at:'2026-10-15T12:00:00Z',order:{number:'ORD-9'}
        }]:[]})};
    }});
    env.run();
    await settle();
    const view=env.roots[0];
    const marker=env.layer.layers[0];
    const button=view.parts.list.children[0];
    marker.openPopup();
    button.focus();

    await view.foodexDriverLiveMap.refresh();

    assert.equal(marker.removed,true);
    assert.equal(env.layer.layers.length,0);
    assert.equal(button.isConnected,false);
    assert.equal(env.document.activeElement,view.parts.search);
    assert.equal(view.parts.state.textContent,i18n.entityRemoved);
    assert.equal(view.foodexDriverLiveMap.rows().length,0);
});

test('background feed failure preserves the last successful map, counters and timestamp',async()=>{
    let failing=false;
    const env=setup({response:async()=>failing ? {ok:false,status:503} : {ok:true,json:async()=>({data:[{driver_id:1,latitude:29,longitude:48,status:'online'}]})}});
    env.run();await settle();const view=env.roots[0];const updated=view.parts.updated.textContent;
    const retainedMarker=env.layer.layers[0];
    assert.equal(view.parts['count-online'].textContent,'1');
    failing=true;await view.foodexDriverLiveMap.refresh();
    assert.equal(view.foodexDriverLiveMap.rows().length,1);
    assert.equal(view.parts['count-online'].textContent,'1');
    assert.equal(view.parts.updated.textContent,updated);
    assert.equal(env.layer.layers[0],retainedMarker);
    assert.equal(view.parts.error.hidden,true);
    assert.equal(view.parts.state.textContent,i18n.serverFailed);

    failing=false;
    await view.foodexDriverLiveMap.refresh();
    assert.equal(view.dataset.liveMapError,undefined);
    assert.equal(view.parts.error.hidden,true);
    assert.equal(view.parts.state.textContent,i18n.ready);
    assert.equal(view.parts['count-online'].textContent,'1');
    assert.equal(env.layer.layers[0],retainedMarker,'successful recovery reconciles the retained marker in place');
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
for (const mode of ['full','compact']) {
    test(`${mode}: bfcache restoration resumes one live-map loop without destroying retained state`,async()=>{
        const env=setup({mode});
        env.run();
        await settle();
        const view=env.roots[0];
        const initialCalls=env.calls.length;
        if (mode === 'full') {
            view.parts.channel.value='b2c';
            view.parts.store.value='7';
            view.parts.search.value='route-a';
        }

        env.events.pagehide({persisted:true});
        assert.equal(env.map.removeCount,0);
        assert.equal(view.dataset.driverLiveMapReady,'1');
        assert.equal(view.dataset.liveMapStale,'1');
        assert.equal(view.parts.state.textContent,i18n.restoring);
        assert.equal([...env.timers.values()].filter(timer=>timer.ms===5000).length,0);
        if (mode === 'full') {
            assert.equal(view.parts.channel.value,'b2c');
            assert.equal(view.parts.store.value,'7');
            assert.equal(view.parts.search.value,'route-a');
        }

        env.events.pageshow({persisted:true});
        await settle();
        assert.equal(env.map.invalidateCount,1);
        assert.equal(env.calls.length,initialCalls+1);
        assert.equal(view.dataset.liveMapStale,undefined);
        assert.equal([...env.timers.values()].filter(timer=>timer.ms===5000).length,1);

        env.events.pagehide({persisted:true});
        env.events.pageshow({persisted:true});
        await settle();
        assert.equal(env.map.removeCount,0);
        assert.equal([...env.timers.values()].filter(timer=>timer.ms===5000).length,1);
    });
}
test('map polling and Leaflet runtime are disposed when the page leaves',async()=>{
    const env=setup();env.run();await settle();const view=env.roots[0];
    assert.equal([...env.timers.values()].filter(timer=>timer.ms===5000).length,1);
    env.events.pagehide({persisted:false});
    assert.equal([...env.timers.values()].filter(timer=>timer.ms===5000).length,0);
    assert.equal(env.map.removeCount,1);
    assert.equal(env.events.pageshow,undefined);
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
