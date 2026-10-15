(() => {
'use strict';
const page=document.body;
const analyticsSite=page?.dataset.analyticsSite||'';
const analyticsBranch=page?.dataset.analyticsBranch||'';
const analyticsContext=page?.dataset.analyticsContext||'';
const commerceBranch=page?.dataset.commerceBranch||analyticsBranch;
const commerceRevision=page?.dataset.commerceRevision||'';
let fulfillmentModes=[];
try{fulfillmentModes=JSON.parse(page?.dataset.commerceModes||'[]');}catch(_){fulfillmentModes=[];}
const uuid=()=>{if(globalThis.crypto?.randomUUID)return crypto.randomUUID();return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,ch=>{const r=Math.random()*16|0,v=ch==='x'?r:(r&3|8);return v.toString(16);});};
let visitorToken='';
try{visitorToken=localStorage.getItem('foodex_so_analytics_visitor')||'';if(visitorToken.length<16){visitorToken=uuid()+'-'+uuid();localStorage.setItem('foodex_so_analytics_visitor',visitorToken);}}catch(_){visitorToken=uuid()+'-'+uuid();}
const analyticsReady=/^[0-9a-f-]{36}$/i.test(analyticsSite)&&/^[0-9a-f-]{36}$/i.test(analyticsBranch);
const track=(eventName,extra={})=>{if(!analyticsReady)return;const body={site_public_id:analyticsSite,branch_public_id:analyticsBranch,event_name:eventName,event_id:uuid(),visitor_token:visitorToken,...extra};if(analyticsContext)body.attribution_context=analyticsContext;fetch('/api/smart-ordering/analytics/events',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(body),keepalive:true,credentials:'same-origin'}).catch(()=>{});};
track('storefront_viewed');

const normalize=value=>(value||'').toLocaleLowerCase().trim();
const search=document.querySelector('[data-menu-search]');
const items=[...document.querySelectorAll('[data-menu-item]')];
if(search){let searchTimer=null;search.addEventListener('input',()=>{const query=normalize(search.value);items.forEach(item=>{const haystack=normalize(item.dataset.search||item.textContent);item.hidden=query!==''&&!haystack.includes(query);});document.querySelectorAll('[data-category]').forEach(category=>{category.hidden=[...category.querySelectorAll('[data-menu-item]')].every(item=>item.hidden);});clearTimeout(searchTimer);searchTimer=setTimeout(()=>{if(query!=='')track('search_performed',{metadata:{query_length:query.length,result_count:items.filter(item=>!item.hidden).length}});},600);});}

let copy={};
try{copy=JSON.parse(document.querySelector('[data-cart-copy]')?.textContent||'{}');}catch(_){copy={};}
const errorMessage=(payload,fallback=copy.error||'Error')=>{
    const errors=payload&&typeof payload==='object'?payload.errors:null;
    if(errors&&typeof errors==='object'){
        for(const value of Object.values(errors)){
            if(Array.isArray(value)&&value[0])return String(value[0]);
            if(typeof value==='string'&&value)return value;
        }
    }
    return String(payload?.message||fallback);
};
const request=async(url,options={})=>{
    const headers={Accept:'application/json',...(options.headers||{})};
    if(options.body)headers['Content-Type']='application/json';
    const response=await fetch(url,{credentials:'same-origin',...options,headers});
    let payload={};
    try{payload=await response.json();}catch(_){payload={};}
    if(!response.ok){const error=new Error(errorMessage(payload));error.status=response.status;throw error;}
    return payload;
};

const cartStorageKey=commerceBranch?`foodex_so_cart:${analyticsSite||'site'}:${commerceBranch}:${commerceRevision||'published'}`:'';
let cartAuth=null;
try{const saved=cartStorageKey?JSON.parse(sessionStorage.getItem(cartStorageKey)||'null'):null;if(saved&&typeof saved.id==='string'&&typeof saved.token==='string')cartAuth=saved;}catch(_){cartAuth=null;}
const persistCart=()=>{if(!cartStorageKey)return;try{if(cartAuth)sessionStorage.setItem(cartStorageKey,JSON.stringify(cartAuth));else sessionStorage.removeItem(cartStorageKey);}catch(_){}};
const resetCart=()=>{cartAuth=null;persistCart();setCartBadge(null);};
const cartHeaders=()=>cartAuth?{'X-Smart-Cart-Token':cartAuth.token}:{};
const ensureCart=async()=>{
    if(cartAuth)return cartAuth;
    if(!/^[0-9a-f-]{36}$/i.test(commerceBranch))throw new Error(copy.error||'Cart is unavailable.');
    const body={branch_public_id:commerceBranch};
    if(/^[0-9a-f-]{36}$/i.test(commerceRevision))body.revision_public_id=commerceRevision;
    if(analyticsContext)body.attribution_context=analyticsContext;
    const payload=await request('/api/smart-ordering/carts',{method:'POST',body:JSON.stringify(body)});
    cartAuth={id:String(payload.cart.public_id),token:String(payload.cart.token),checkoutKey:uuid()};
    persistCart();
    return cartAuth;
};
const cartUrl=suffix=>`/api/smart-ordering/carts/${encodeURIComponent(cartAuth.id)}${suffix||''}`;
const formatMoney=quote=>{
    const amount=Number(quote?.grand_total||0),currency=String(quote?.currency||'');
    try{return currency?new Intl.NumberFormat(document.documentElement.lang||'en',{style:'currency',currency}).format(amount):amount.toFixed(3);}
    catch(_){return `${amount.toFixed(3)} ${currency}`.trim();}
};
const cartCount=document.querySelector('[data-cart-count]');
const cartMobileTotal=document.querySelector('[data-cart-mobile-total]');
const setCartBadge=data=>{
    const cartItems=Array.isArray(data?.items)?data.items:[];
    const count=cartItems.reduce((sum,item)=>sum+Number(item.quantity||0),0);
    if(cartCount){cartCount.textContent=String(count);cartCount.hidden=count<=0;}
    if(cartMobileTotal)cartMobileTotal.textContent=count>0?formatMoney(data?.quote):'';
};

const menuCatalog=new Map();
document.querySelectorAll('[data-open-item]').forEach(trigger=>{
    const id=trigger.dataset.itemPublicId||'';
    if(!id||menuCatalog.has(id))return;
    let groups=[];
    try{groups=JSON.parse(trigger.dataset.modifiers||'[]');}catch(_){groups=[];}
    const options=new Map();
    groups.forEach(group=>(group.options||[]).forEach(option=>options.set(String(option.public_key||''),String(option.name||''))));
    menuCatalog.set(id,{name:trigger.dataset.name||copy.line||'Item',options});
});

const dialog=document.querySelector('[data-item-dialog]');
const itemErrors=dialog?.querySelector('[data-item-errors]');
const itemStatus=dialog?.querySelector('[data-item-status]');
const itemQuantity=dialog?.querySelector('[data-dialog-quantity]');
const addCartButton=dialog?.querySelector('[data-add-cart]');
let dialogTrigger=null;
let currentItemId='';
if(dialog&&typeof dialog.showModal==='function'){
    document.addEventListener('click',event=>{
        const trigger=event.target.closest('[data-open-item]');
        if(!trigger||trigger.disabled)return;
        dialogTrigger=trigger;
        currentItemId=trigger.dataset.itemPublicId||'';
        track('product_viewed');
        dialog.querySelector('[data-dialog-name]').textContent=trigger.dataset.name||'';
        const descriptionEl=dialog.querySelector('[data-dialog-description]'),description=trigger.dataset.description||'';
        descriptionEl.textContent=description;descriptionEl.hidden=description==='';
        if(description==='')dialog.removeAttribute('aria-describedby');else dialog.setAttribute('aria-describedby','so-item-dialog-description');
        const image=dialog.querySelector('[data-dialog-image]'),imageUrl=trigger.dataset.image||'';
        image.hidden=imageUrl==='';if(imageUrl)image.src=imageUrl;
        if(itemQuantity)itemQuantity.value='1';
        if(itemErrors){itemErrors.hidden=true;itemErrors.textContent='';}
        if(itemStatus)itemStatus.textContent='';
        const modifierHost=dialog.querySelector('[data-dialog-modifiers]');modifierHost.replaceChildren();
        let groups=[];try{groups=JSON.parse(trigger.dataset.modifiers||'[]');}catch(_){groups=[];}
        if(groups.length>0)track('modifier_opened');
        groups.forEach((group,index)=>{
            const fieldset=document.createElement('fieldset'),legend=document.createElement('legend');
            const minimum=Math.max(Number(group.min||0),group.required?1:0),maximum=Math.max(1,Number(group.max||1));
            fieldset.dataset.groupId=group.public_key||'';fieldset.dataset.min=String(minimum);fieldset.dataset.max=String(maximum);
            legend.textContent=`${group.name||''}${group.required?' *':''}`;fieldset.appendChild(legend);
            (group.options||[]).forEach(option=>{
                if(option.available===false)return;
                const label=document.createElement('label'),input=document.createElement('input');
                input.type=maximum>1?'checkbox':'radio';input.name=`modifier-${index}`;input.value=option.public_key||'';
                label.append(input,document.createTextNode(` ${option.name||''} ${option.price_delta_display||''}`));fieldset.appendChild(label);
            });
            const error=document.createElement('p');error.className='so-cart-error';error.hidden=true;error.dataset.modifierError='';
            fieldset.appendChild(error);modifierHost.appendChild(fieldset);
        });
        dialog.showModal();dialog.querySelector('.so-dialog-close')?.focus();
    });
    dialog.addEventListener('close',()=>{const trigger=dialogTrigger;dialogTrigger=null;currentItemId='';if(trigger instanceof HTMLElement&&trigger.isConnected&&!trigger.disabled)trigger.focus();});
}

const selectedModifiers=()=>{
    const selections=[];let firstInvalid=null;
    dialog?.querySelectorAll('[data-dialog-modifiers] fieldset').forEach(fieldset=>{
        const selected=[...fieldset.querySelectorAll('input:checked')].map(input=>input.value).filter(Boolean);
        const minimum=Number(fieldset.dataset.min||0),maximum=Number(fieldset.dataset.max||1);
        const error=fieldset.querySelector('[data-modifier-error]');
        const valid=selected.length>=minimum&&selected.length<=maximum;
        if(error){error.hidden=valid;error.textContent=valid?'':(selected.length<minimum?copy.requiredModifier:copy.modifierRange);}
        fieldset.setAttribute('aria-invalid',valid?'false':'true');
        if(!valid&&!firstInvalid)firstInvalid=fieldset.querySelector('input');
        selections.push({group_public_id:fieldset.dataset.groupId||'',option_public_ids:selected});
    });
    if(firstInvalid){firstInvalid.focus();return null;}
    return selections;
};

const mutateWithCart=async(action,retry=true)=>{
    await ensureCart();
    try{return await action();}
    catch(error){
        if(retry&&(error.status===404||error.status===410)){resetCart();await ensureCart();return action();}
        throw error;
    }
};

addCartButton?.addEventListener('click',async()=>{
    const modifiers=selectedModifiers();
    const quantity=Number(itemQuantity?.value||0);
    if(!modifiers||!Number.isFinite(quantity)||quantity<=0){if(itemErrors&&modifiers){itemErrors.textContent=copy.error||'';itemErrors.hidden=false;}return;}
    addCartButton.disabled=true;addCartButton.setAttribute('aria-busy','true');
    if(itemStatus)itemStatus.textContent=copy.adding||'';
    if(itemErrors)itemErrors.hidden=true;
    try{
        await mutateWithCart(()=>request(cartUrl('/items'),{method:'POST',headers:cartHeaders(),body:JSON.stringify({menu_item_public_id:currentItemId,quantity,selection:{menu_modifiers:modifiers}})}));
        await loadCart();
        if(itemStatus)itemStatus.textContent=copy.added||'';
        dialog.close();
    }catch(error){if(itemErrors){itemErrors.textContent=error.message||copy.error||'';itemErrors.hidden=false;}if(itemStatus)itemStatus.textContent='';}
    finally{addCartButton.disabled=false;addCartButton.removeAttribute('aria-busy');}
});

const cartDialog=document.querySelector('[data-cart-dialog]');
const cartLines=document.querySelector('[data-cart-lines]');
const cartEmpty=document.querySelector('[data-cart-empty]');
const cartSummary=document.querySelector('[data-cart-summary]');
const cartTotal=document.querySelector('[data-cart-total]');
const cartStatus=document.querySelector('[data-cart-status]');
const checkoutToggle=document.querySelector('[data-checkout-toggle]');
const checkoutPanel=document.querySelector('[data-checkout-panel]');
const checkoutForm=document.querySelector('[data-checkout-form]');
const checkoutError=document.querySelector('[data-checkout-error]');
const orderConfirmation=document.querySelector('[data-order-confirmation]');
const orderReference=document.querySelector('[data-order-reference]');
const modeSelect=document.querySelector('[data-fulfillment-mode]');
const deliveryFields=document.querySelector('[data-delivery-fields]');
const dineinHint=document.querySelector('[data-dinein-hint]');
const locationStatus=document.querySelector('[data-location-status]');
let cartTrigger=null;
let deliveryCoordinates=null;

const optionNames=item=>{
    const catalog=menuCatalog.get(String(item.menu_item_public_id||''));if(!catalog)return [];
    const rows=Array.isArray(item.selection?.menu_modifiers)?item.selection.menu_modifiers:[];
    return rows.flatMap(row=>(row.option_public_ids||[]).map(id=>catalog.options.get(String(id))).filter(Boolean));
};
const renderCart=data=>{
    if(!cartLines)return;
    cartLines.replaceChildren();
    const cartItems=Array.isArray(data?.items)?data.items:[];
    cartItems.forEach(item=>{
        const article=document.createElement('article'),copyNode=document.createElement('div'),title=document.createElement('strong');
        const catalog=menuCatalog.get(String(item.menu_item_public_id||''));title.textContent=catalog?.name||copy.line||'Item';copyNode.appendChild(title);
        const options=optionNames(item);if(options.length){const details=document.createElement('small');details.textContent=options.join(' · ');copyNode.appendChild(details);}
        const controls=document.createElement('div'),label=document.createElement('label'),labelText=document.createElement('span'),quantity=document.createElement('input');
        labelText.textContent=copy.quantity||'';quantity.type='number';quantity.min='1';quantity.max='999';quantity.step='1';quantity.value=String(item.quantity||1);quantity.dataset.lineQty='';
        label.append(labelText,quantity);
        const update=document.createElement('button');update.type='button';update.dataset.lineUpdate=String(item.public_id);update.textContent=copy.update||'Update';
        const remove=document.createElement('button');remove.type='button';remove.dataset.lineRemove=String(item.public_id);remove.textContent=copy.remove||'Remove';
        controls.append(label,update,remove);article.append(copyNode,controls);cartLines.appendChild(article);
    });
    const hasItems=cartItems.length>0;
    if(cartEmpty)cartEmpty.hidden=hasItems;
    if(cartSummary)cartSummary.hidden=!hasItems;
    if(checkoutToggle)checkoutToggle.hidden=!hasItems||fulfillmentModes.length===0;
    if(cartTotal)cartTotal.textContent=formatMoney(data?.quote);
    setCartBadge(data);
};

const loadCart=async()=>{
    if(!cartAuth){renderCart({items:[],quote:{}});return null;}
    if(cartStatus)cartStatus.textContent=copy.loading||'';
    try{
        const payload=await request(cartUrl(''),{headers:cartHeaders()});
        renderCart(payload.data);if(cartStatus)cartStatus.textContent='';return payload.data;
    }catch(error){
        if(error.status===404||error.status===410){resetCart();renderCart({items:[],quote:{}});if(cartStatus)cartStatus.textContent=copy.cartUnavailable||copy.error||'';return null;}
        if(cartStatus)cartStatus.textContent=error.message||copy.error||'';throw error;
    }
};

document.addEventListener('click',async event=>{
    const view=event.target.closest('[data-view-cart]');
    if(view&&cartDialog&&typeof cartDialog.showModal==='function'){
        event.preventDefault();cartTrigger=view;if(checkoutPanel)checkoutPanel.hidden=true;if(orderConfirmation)orderConfirmation.hidden=true;
        cartDialog.showModal();cartDialog.querySelector('[data-cart-close]')?.focus();
        try{await loadCart();}catch(_){}
        return;
    }
    const update=event.target.closest('[data-line-update]');
    if(update&&cartAuth){
        const input=update.parentElement?.querySelector('[data-line-qty]'),quantity=Number(input?.value||0);
        if(quantity<=0)return;
        update.disabled=true;if(cartStatus)cartStatus.textContent=copy.updating||'';
        try{await request(cartUrl('/items/'+encodeURIComponent(update.dataset.lineUpdate)),{method:'PATCH',headers:cartHeaders(),body:JSON.stringify({quantity})});await loadCart();}
        catch(error){if(cartStatus)cartStatus.textContent=error.message||copy.error||'';}finally{update.disabled=false;}
        return;
    }
    const remove=event.target.closest('[data-line-remove]');
    if(remove&&cartAuth){
        remove.disabled=true;if(cartStatus)cartStatus.textContent=copy.updating||'';
        try{await request(cartUrl('/items/'+encodeURIComponent(remove.dataset.lineRemove)),{method:'DELETE',headers:cartHeaders()});await loadCart();if(cartStatus)cartStatus.textContent=copy.removed||'';}
        catch(error){if(cartStatus)cartStatus.textContent=error.message||copy.error||'';}finally{remove.disabled=false;}
    }
});

document.querySelector('[data-cart-close]')?.addEventListener('click',()=>cartDialog?.close());
cartDialog?.addEventListener('close',()=>{const trigger=cartTrigger;cartTrigger=null;if(trigger instanceof HTMLElement&&trigger.isConnected)trigger.focus();});
checkoutToggle?.addEventListener('click',()=>{if(!checkoutPanel)return;checkoutPanel.hidden=false;checkoutPanel.querySelector('input,select,textarea,button')?.focus();});
const syncFulfillment=()=>{
    const mode=modeSelect?.value||'';
    if(deliveryFields)deliveryFields.hidden=mode!=='delivery';
    if(dineinHint)dineinHint.hidden=mode!=='dine_in';
};
modeSelect?.addEventListener('change',syncFulfillment);syncFulfillment();

document.querySelector('[data-use-location]')?.addEventListener('click',()=>{
    if(locationStatus)locationStatus.textContent=copy.locationRequest||'';
    if(!navigator.geolocation){if(locationStatus)locationStatus.textContent=copy.locationError||'';return;}
    navigator.geolocation.getCurrentPosition(position=>{
        deliveryCoordinates={latitude:position.coords.latitude,longitude:position.coords.longitude};
        if(locationStatus)locationStatus.textContent=copy.locationReady||'';
    },()=>{deliveryCoordinates=null;if(locationStatus)locationStatus.textContent=copy.locationError||'';},{enableHighAccuracy:false,timeout:10000,maximumAge:300000});
});

checkoutForm?.addEventListener('submit',async event=>{
    event.preventDefault();
    if(!cartAuth)return;
    const submit=checkoutForm.querySelector('[data-place-order]'),form=new FormData(checkoutForm),mode=String(form.get('fulfillment_mode')||'');
    const payload={fulfillment_mode:mode,name:String(form.get('name')||''),phone:String(form.get('phone')||''),email:String(form.get('email')||''),order_note:String(form.get('order_note')||'')};
    if(mode==='delivery'){
        if(!deliveryCoordinates){if(checkoutError){checkoutError.textContent=copy.deliveryLocation||'';checkoutError.hidden=false;}return;}
        payload.delivery_address=String(form.get('delivery_address')||'');payload.delivery_latitude=deliveryCoordinates.latitude;payload.delivery_longitude=deliveryCoordinates.longitude;
    }
    if(mode==='dine_in'){
        const params=new URLSearchParams(location.search),tablePublicId=params.get('table_public_id')||'',tableToken=params.get('table_token')||'';
        if(!tablePublicId||!tableToken){if(checkoutError){checkoutError.textContent=copy.tableContext||'';checkoutError.hidden=false;}return;}
        payload.table_public_id=tablePublicId;payload.table_token=tableToken;
    }
    if(checkoutError)checkoutError.hidden=true;
    if(!cartAuth.checkoutKey){cartAuth.checkoutKey=uuid();persistCart();}
    submit.disabled=true;submit.setAttribute('aria-busy','true');if(cartStatus)cartStatus.textContent=copy.placing||'';
    try{
        const result=await request(cartUrl('/checkout'),{method:'POST',headers:{...cartHeaders(),'Idempotency-Key':cartAuth.checkoutKey},body:JSON.stringify(payload)});
        if(orderReference)orderReference.textContent=(copy.orderPlaced||':reference').replace(':reference',String(result.data.reference||result.data.order_number||''));
        if(orderConfirmation){orderConfirmation.hidden=false;orderConfirmation.focus();}
        if(checkoutPanel)checkoutPanel.hidden=true;if(cartLines)cartLines.replaceChildren();if(cartSummary)cartSummary.hidden=true;if(checkoutToggle)checkoutToggle.hidden=true;
        resetCart();if(cartStatus)cartStatus.textContent='';
    }catch(error){if(checkoutError){checkoutError.textContent=error.message||copy.error||'';checkoutError.hidden=false;}if(cartStatus)cartStatus.textContent='';}
    finally{submit.disabled=false;submit.removeAttribute('aria-busy');}
});

if(cartAuth)loadCart().catch(()=>{});
if('IntersectionObserver' in window){const seen=new WeakSet();const observer=new IntersectionObserver(entries=>{entries.forEach(entry=>{if(entry.isIntersecting&&!seen.has(entry.target)){seen.add(entry.target);track('category_viewed');observer.unobserve(entry.target);}});},{threshold:.35});document.querySelectorAll('[data-category]').forEach(category=>observer.observe(category));}
})();