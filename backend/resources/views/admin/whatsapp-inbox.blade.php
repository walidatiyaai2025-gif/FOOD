<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('whatsapp.inbox.page_title') }}</title>
@include('admin._brand-components')
<style>
.wa-shell{display:grid;gap:14px}.wa-toolbar,.wa-profile{padding:16px}.wa-toolbar form{display:grid;grid-template-columns:2fr repeat(4,minmax(130px,1fr));gap:10px;align-items:end}
.wa-workspace{display:grid;grid-template-columns:minmax(250px,.9fr) minmax(360px,1.6fr) minmax(280px,1fr);gap:14px;min-height:68vh}
.wa-pane{padding:0;overflow:hidden}.wa-pane-head{padding:14px 16px;border-bottom:1px solid var(--foodex-border);display:flex;justify-content:space-between;gap:10px;align-items:center}.wa-pane-body{padding:14px 16px}
.wa-list{display:grid}.wa-row{display:block;padding:14px 16px;border-bottom:1px solid var(--foodex-border);text-decoration:none;color:inherit}.wa-row:hover,.wa-row.active{background:var(--foodex-green-soft)}.wa-row-top{display:flex;justify-content:space-between;gap:10px}.wa-row p{margin:4px 0;color:var(--foodex-muted);font-size:12px}
.wa-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:800;background:var(--foodex-background)}.wa-badge.wait{background:var(--foodex-orange-soft);color:var(--foodex-orange)}.wa-badge.open{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.wa-badge.resolved{background:var(--foodex-background);color:var(--foodex-muted)}.wa-unread{min-width:22px;height:22px;border-radius:99px;background:var(--foodex-green-dark);color:var(--foodex-surface);text-align:center;line-height:22px;font-size:11px}
.wa-timeline{display:grid;gap:10px;max-height:46vh;overflow:auto;padding:16px}.wa-message{max-width:82%;padding:11px 13px;border-radius:14px;background:var(--foodex-background)}.wa-message.outbound{margin-inline-start:auto;background:var(--foodex-green-soft)}.wa-message.note{max-width:100%;background:var(--foodex-orange-soft);border:1px dashed var(--foodex-orange)}.wa-message small{display:block;color:var(--foodex-muted);margin-top:5px}
.wa-attachment{display:grid;gap:7px;margin-bottom:8px}.wa-attachment img,.wa-attachment video{display:block;max-width:min(360px,100%);max-height:320px;border-radius:12px;border:1px solid var(--foodex-border);background:var(--foodex-surface)}.wa-attachment audio{width:min(360px,100%)}.wa-attachment-link{display:inline-flex;align-items:center;gap:8px;width:max-content;max-width:100%;padding:9px 11px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-surface);color:var(--foodex-green-dark);text-decoration:none;font-weight:700}.wa-attachment-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:280px}
.wa-compose{border-top:1px solid var(--foodex-border);padding:14px 16px;display:grid;gap:10px}.wa-compose textarea{width:100%;min-height:82px}.wa-compose-file{display:grid;gap:5px;margin:9px 0}.wa-compose-file input{width:100%;padding:9px;border:1px dashed var(--foodex-border);border-radius:10px;background:var(--foodex-surface)}.wa-inline{display:flex;gap:8px;flex-wrap:wrap;align-items:end}.wa-inline label{display:grid;gap:4px;min-width:130px}
.wa-360{display:grid;gap:12px}.wa-card{border:1px solid var(--foodex-border);border-radius:12px;padding:12px}.wa-card h3{margin:0 0 9px;font-size:14px}.wa-card p{margin:4px 0;font-size:13px}.wa-mini{width:100%;border-collapse:collapse}.wa-mini td{padding:6px 0;border-bottom:1px solid var(--foodex-border);font-size:12px}.wa-tags{display:flex;gap:6px;flex-wrap:wrap}.wa-tag{padding:4px 8px;background:var(--foodex-background);border-radius:999px;font-size:11px;font-weight:700}
.wa-empty{padding:32px;text-align:center;color:var(--foodex-muted)}.wa-status{display:grid;grid-template-columns:repeat(3,minmax(120px,1fr)) auto;gap:8px;align-items:end}
@media(max-width:1180px){.wa-workspace{grid-template-columns:300px 1fr}.wa-context{grid-column:1/-1}.wa-toolbar form{grid-template-columns:repeat(3,1fr)}}
@media(max-width:760px){.wa-toolbar form,.wa-status{grid-template-columns:1fr}.wa-workspace{grid-template-columns:1fr}.wa-context{grid-column:auto}.wa-message{max-width:94%}}
</style>
</head>
<body>
<div class="foodex-admin-layout">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
        <header class="foodex-page-header">
            <div>
                <span class="foodex-subtitle">{{ __('whatsapp.inbox.subtitle') }}</span>
                <h1>{{ __('whatsapp.inbox.heading') }}</h1>
                <p>{{ __('whatsapp.inbox.intro') }}</p>
            </div>
            @include('admin._live-notifications',['user'=>auth()->user()])
        </header>

        @if(session('status'))<div class="foodex-card" style="padding:12px 16px;margin-bottom:12px" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="foodex-card" style="padding:12px 16px;margin-bottom:12px;color:var(--foodex-red)"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="wa-shell">
            <section class="foodex-card wa-profile">
                <form method="post" action="{{ route('admin.whatsapp-inbox.status') }}" class="wa-status">
                    @csrf @method('PATCH')
                    <label>{{ __('whatsapp.inbox.my_status') }}
                        <select name="status">
                            @foreach(['ONLINE','BUSY','AWAY','OFFLINE'] as $status)
                                <option value="{{ $status }}" @selected(($profile->status??'OFFLINE')===$status)>{{ __('whatsapp.inbox.agent_statuses.'.$status) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>{{ __('whatsapp.inbox.capacity') }}<input type="number" min="1" max="500" name="capacity" value="{{ $profile->capacity??20 }}"></label>
                    <label>{{ __('whatsapp.inbox.accept_new') }}
                        <select name="accepts_new"><option value="1" @selected(($profile->accepts_new??1)==1)>{{ __('whatsapp.inbox.yes') }}</option><option value="0" @selected(($profile->accepts_new??1)==0)>{{ __('whatsapp.inbox.no') }}</option></select>
                    </label>
                    <button class="foodex-action-primary" type="submit">{{ __('whatsapp.inbox.update') }}</button>
                </form>
            </section>

            <section class="foodex-card wa-toolbar">
                <form method="get">
                    <label>{{ __('whatsapp.inbox.search') }}<input name="q" value="{{ $filters['q']??'' }}" placeholder="{{ __('whatsapp.inbox.search_placeholder') }}"></label>
                    <label>{{ __('whatsapp.inbox.status') }}
                        <select name="status"><option value="">{{ __('whatsapp.inbox.all') }}</option>@foreach(['OPEN','WAITING_FOR_AGENT','WAITING_FOR_CUSTOMER','RESOLVED','CLOSED'] as $status)<option value="{{ $status }}" @selected(($filters['status']??'')===$status)>{{ __('whatsapp.inbox.conversation_statuses.'.$status) }}</option>@endforeach</select>
                    </label>
                    <label>{{ __('whatsapp.inbox.assignment') }}
                        <select name="assignment">@foreach(['all','mine','unassigned'] as $value)<option value="{{ $value }}" @selected(($filters['assignment']??'all')===$value)>{{ __('whatsapp.inbox.assignment_filters.'.$value) }}</option>@endforeach</select>
                    </label>
                    <label>{{ __('whatsapp.inbox.store') }}
                        <select name="store_id"><option value="">{{ __('whatsapp.inbox.all_authorized') }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string)($filters['store_id']??'')===(string)$store->id)>{{ $store->name }}</option>@endforeach</select>
                    </label>
                    <label>{{ __('whatsapp.inbox.channel') }}<input name="channel" value="{{ $filters['channel']??'' }}" placeholder="{{ __('whatsapp.inbox.channels.whatsapp') }}"></label>
                    <button class="foodex-action-primary" type="submit">{{ __('whatsapp.inbox.apply') }}</button>
                </form>
            </section>

            <div class="wa-workspace">
                <section class="foodex-card wa-pane">
                    <div class="wa-pane-head"><strong>{{ __('whatsapp.inbox.conversations') }}</strong><span>{{ $conversations->total() }}</span></div>
                    <div class="wa-list">
                    @forelse($conversations as $row)
                        @php($active=$selected && (int)$selected->id===(int)$row->id)
                        <a class="wa-row {{ $active?'active':'' }}" href="{{ route('admin.whatsapp-inbox.index', array_merge(request()->except('page','conversation'),['conversation'=>$row->id])) }}">
                            <div class="wa-row-top"><strong>{{ $row->customer_name ?: __('whatsapp.inbox.unknown_customer') }}</strong>@if($row->unread_count>0)<span class="wa-unread">{{ $row->unread_count }}</span>@endif</div>
                            <p>{{ $row->store_name ?: '-' }} · {{ __('whatsapp.inbox.channels.'.strtolower((string)$row->channel)) }} · {{ $row->agent_name ?: __('whatsapp.inbox.unassigned') }}</p>
                            <span class="wa-badge {{ str_contains($row->status,'WAITING')?'wait':($row->status==='OPEN'?'open':'resolved') }}">{{ __('whatsapp.inbox.conversation_statuses.'.$row->status) }}</span>
                        </a>
                    @empty
                        <div class="wa-empty">{{ __('whatsapp.inbox.no_conversations') }}</div>
                    @endforelse
                    </div>
                    <div class="wa-pane-body">{{ $conversations->links() }}</div>
                </section>

                <section class="foodex-card wa-pane">
                    @if($selected)
                    <div class="wa-pane-head">
                        <div><strong>{{ $selected->customer_name ?: __('whatsapp.inbox.customer') }}</strong><div style="font-size:12px;color:var(--foodex-muted)">{{ $selected->customer_phone ?: $selected->customer_email }}</div></div>
                        <span class="wa-badge">{{ __('whatsapp.inbox.conversation_statuses.'.$selected->status) }}</span>
                    </div>
                    <div class="wa-pane-body">
                        <div class="wa-inline" style="margin-bottom:10px">
                            @foreach($tags as $tag)<span class="wa-tag">{{ $tag->name }}</span>@endforeach
                        </div>
                        <form method="post" action="{{ route('admin.whatsapp-inbox.assign',['conversation'=>$selected->id]) }}" class="wa-inline">
                            @csrf @method('PATCH')
                            <input type="hidden" name="expected_version" value="{{ $selected->lock_version }}">
                            <label>{{ __('whatsapp.inbox.primary_agent') }}<select name="agent_id" required><option value="">{{ __('whatsapp.inbox.choose') }}</option>@foreach($agents as $agent)<option value="{{ $agent->id }}" @selected((int)$selected->primary_agent_id===$agent->id)>{{ $agent->name }} · {{ $agent->load }}/{{ $agent->capacity }}</option>@endforeach</select></label>
                            <label>{{ __('whatsapp.inbox.backup') }}<select name="backup_agent_id"><option value="">{{ __('whatsapp.inbox.none') }}</option>@foreach($agents as $agent)<option value="{{ $agent->id }}">{{ $agent->name }}</option>@endforeach</select></label>
                            <label>{{ __('whatsapp.inbox.reason') }}<input name="reason" maxlength="500"></label>
                            <button class="foodex-action-secondary" type="submit">{{ __('whatsapp.inbox.assign_reassign') }}</button>
                        </form>
                        <div class="wa-inline" style="margin-top:10px">
                            <form method="post" action="{{ route('admin.whatsapp-inbox.transition',['conversation'=>$selected->id]) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="{{ $transitionAction }}">@if($selectedIsResolved)<button class="foodex-action-secondary" type="submit">{{ __('whatsapp.inbox.reopen') }}</button>@else<button class="foodex-action-secondary" type="submit">{{ __('whatsapp.inbox.resolve') }}</button>@endif</form>
                            <form method="post" action="{{ route('admin.whatsapp-inbox.tag',['conversation'=>$selected->id]) }}" class="wa-inline">@csrf
                                <select name="tag_id" required><option value="">{{ __('whatsapp.inbox.tag') }}</option>@foreach($allTags as $tag)<option value="{{ $tag->id }}">{{ $tag->name }}</option>@endforeach</select>
                                <select name="action"><option value="attach">{{ __('whatsapp.inbox.attach') }}</option><option value="detach">{{ __('whatsapp.inbox.detach') }}</option></select>
                                <button class="foodex-action-secondary" type="submit">{{ __('whatsapp.inbox.update_tag') }}</button>
                            </form>
                        </div>
                    </div>

                    <div class="wa-timeline">
                        @forelse($timeline as $item)
                            <div class="wa-message {{ $item['kind']==='note'?'note':$item['direction'] }}">
                                @if($item['kind']==='note')<strong>🔒 {{ __('whatsapp.inbox.internal_note') }}</strong>@endif
                                @if(!empty($item['attachment']))
                                    @php($attachment=$item['attachment'])
                                    <div class="wa-attachment">
                                        @if(in_array($attachment['media_type'], ['image','sticker'], true))
                                            <a href="{{ $attachment['url'] }}" target="_blank" rel="noopener">
                                                <img src="{{ $attachment['url'] }}" alt="{{ $attachment['filename'] ?: __('whatsapp.inbox.attachment') }}" loading="lazy">
                                            </a>
                                        @elseif($attachment['media_type']==='video')
                                            <video controls preload="metadata">
                                                <source src="{{ $attachment['url'] }}" @if($attachment['mime_type']) type="{{ $attachment['mime_type'] }}" @endif>
                                            </video>
                                        @elseif($attachment['media_type']==='audio')
                                            <audio controls preload="metadata">
                                                <source src="{{ $attachment['url'] }}" @if($attachment['mime_type']) type="{{ $attachment['mime_type'] }}" @endif>
                                            </audio>
                                        @else
                                            <a class="wa-attachment-link" href="{{ $attachment['url'] }}" target="_blank" rel="noopener">
                                                <span>📎</span>
                                                <span class="wa-attachment-name">{{ $attachment['filename'] ?: __('whatsapp.inbox.attachment') }}</span>
                                            </a>
                                        @endif
                                        @if($attachment['filename'] && $attachment['media_type']!=='document')
                                            <a class="wa-attachment-link" href="{{ $attachment['url'] }}" target="_blank" rel="noopener">
                                                <span>📎</span><span class="wa-attachment-name">{{ $attachment['filename'] }}</span>
                                            </a>
                                        @endif
                                    </div>
                                @endif
                                @if(filled($item['body']))
                                    <div>{{ $item['body'] }}</div>
                                @elseif(empty($item['attachment']))
                                    <div>{{ __('whatsapp.inbox.attachment') }}</div>
                                @endif
                                <small>{{ $item['actor'] }} · {{ $item['timestamp'] }} @if($item['delivery_status']) · {{ __('whatsapp.inbox.delivery_statuses.'.$item['delivery_status']) }}@endif</small>
                            </div>
                        @empty
                            <div class="wa-empty">{{ __('whatsapp.inbox.no_messages') }}</div>
                        @endforelse
                    </div>

                    <div class="wa-compose">
                        <form method="post" enctype="multipart/form-data" action="{{ route('admin.whatsapp-inbox.reply',['conversation'=>$selected->id]) }}">@csrf
                            <label><strong>{{ __('whatsapp.inbox.customer_reply') }}</strong><textarea name="body" maxlength="5000" placeholder="{{ __('whatsapp.inbox.caption_optional') }}"></textarea></label>
                            <label class="wa-compose-file">
                                <strong>{{ __('whatsapp.inbox.send_attachment') }}</strong>
                                <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.txt,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.mp4,.3gp,.mp3,.m4a,.aac,.amr,.ogg,.opus">
                                <small>{{ __('whatsapp.inbox.attachment_hint') }}</small>
                            </label>
                            <button class="foodex-action-primary" type="submit">{{ __('whatsapp.inbox.queue_transport') }}</button>
                            <small>{{ __('whatsapp.inbox.transport_hint') }}</small>
                        </form>
                        <form method="post" action="{{ route('admin.whatsapp-inbox.note',['conversation'=>$selected->id]) }}">@csrf
                            <label><strong>{{ __('whatsapp.inbox.internal_note_only') }}</strong><textarea name="body" required maxlength="5000"></textarea></label>
                            <button class="foodex-action-secondary" type="submit">{{ __('whatsapp.inbox.save_note') }}</button>
                        </form>
                    </div>
                    @else
                        <div class="wa-empty">{{ __('whatsapp.inbox.choose_conversation') }}</div>
                    @endif
                </section>

                <section class="foodex-card wa-pane wa-context">
                    <div class="wa-pane-head"><strong>{{ __('whatsapp.inbox.customer_360') }}</strong></div>
                    <div class="wa-pane-body wa-360">
                    @if($customer360)
                        @php($customer=$customer360['customer'])
                        <div class="wa-card">
                            <h3>{{ $customer->name }}</h3>
                            <p>{{ $customer->phone ?: '-' }}</p><p>{{ $customer->email }}</p>
                            <p>{{ __('whatsapp.inbox.origin') }}: {{ $customer->origin_channel ? __('whatsapp.inbox.channels.'.strtolower((string)$customer->origin_channel)) : '-' }}</p>
                            <p>{{ __('whatsapp.inbox.store') }}: {{ $customer->originStore?->name ?: '-' }}</p>
                        </div>
                        <div class="wa-card"><h3>{{ __('whatsapp.inbox.ownership') }}</h3>
                            <p>{{ __('whatsapp.inbox.primary') }}: {{ $customer360['ownership']->primary_agent_name??__('whatsapp.inbox.unassigned') }}</p>
                            <p>{{ __('whatsapp.inbox.backup') }}: {{ $customer360['ownership']->backup_agent_name??'-' }}</p>
                            <p>{{ __('whatsapp.inbox.source') }}: {{ isset($customer360['ownership']->source) ? __('whatsapp.inbox.ownership_sources.'.$customer360['ownership']->source) : '-' }}</p>
                        </div>
                        <div class="wa-card"><h3>{{ __('whatsapp.inbox.commercial_activity') }}</h3>
                            <p>{{ __('whatsapp.inbox.orders') }}: {{ $customer360['orders_count'] }}</p>
                            <p>{{ __('whatsapp.inbox.order_value') }}: {{ number_format($customer360['orders_value'],3) }}</p>
                            <p>{{ __('whatsapp.inbox.invoices') }}: {{ $customer360['invoices_count'] }}</p>
                        </div>
                        <div class="wa-card"><h3>{{ __('whatsapp.inbox.recent_orders') }}</h3><table class="wa-mini">@forelse($customer360['orders'] as $order)<tr><td>{{ $order->order_number }}</td><td>{{ __('whatsapp.inbox.status_values.'.strtolower((string)$order->status)) }}</td><td>{{ number_format((float)$order->grand_total,3) }} {{ __('whatsapp.inbox.currencies.'.strtolower((string)$order->currency)) }}</td></tr>@empty<tr><td>{{ __('whatsapp.inbox.none') }}</td></tr>@endforelse</table></div>
                        <div class="wa-card"><h3>{{ __('whatsapp.inbox.recent_invoices') }}</h3><table class="wa-mini">@forelse($customer360['invoices'] as $invoice)<tr><td>{{ $invoice->invoice_number }}</td><td>{{ __('whatsapp.inbox.status_values.'.strtolower((string)$invoice->status)) }}</td><td>{{ number_format((float)$invoice->total,3) }} {{ __('whatsapp.inbox.currencies.'.strtolower((string)$invoice->currency)) }}</td></tr>@empty<tr><td>{{ __('whatsapp.inbox.none') }}</td></tr>@endforelse</table></div>
                        <div class="wa-card"><h3>{{ __('whatsapp.inbox.recent_payments') }}</h3><table class="wa-mini">@forelse($customer360['payments'] as $payment)<tr><td>{{ __('whatsapp.inbox.payment_providers.'.strtolower((string)$payment->provider)) }}</td><td>{{ __('whatsapp.inbox.status_values.'.strtolower((string)$payment->status)) }}</td><td>{{ number_format((float)$payment->amount,3) }} {{ __('whatsapp.inbox.currencies.'.strtolower((string)$payment->currency)) }}</td></tr>@empty<tr><td>{{ __('whatsapp.inbox.none') }}</td></tr>@endforelse</table></div>
                    @else
                        <div class="wa-empty">{{ __('whatsapp.inbox.no_customer_identity') }}</div>
                    @endif
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>
</body>
</html>
