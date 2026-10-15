@php($globalSearchId = 'foodex-global-search-'.substr(md5((string) request()->path()), 0, 8))
<style id="foodex-global-search-style">
.foodex-global-search{position:relative}
.foodex-global-search-label{display:block;margin-bottom:5px;color:var(--foodex-muted);font-size:var(--foodex-text-xs);font-weight:700}
.foodex-global-search-control{position:relative}
.foodex-global-search-control .foodex-svg-icon{position:absolute;inset-inline-start:11px;top:13px;width:18px;height:18px;color:var(--foodex-muted);pointer-events:none}
.foodex-global-search-input{width:100%;padding-inline-start:38px!important}
.foodex-global-search-results{position:absolute;z-index:12020;inset-inline:0;top:calc(100% + 6px);max-height:min(60vh,420px);overflow:auto;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);box-shadow:var(--foodex-shadow);padding:6px}
.foodex-global-search-results[hidden]{display:none}
.foodex-global-search-result{display:block;padding:9px 10px;border-radius:9px;color:var(--foodex-ink);text-decoration:none}
.foodex-global-search-result:hover,.foodex-global-search-result[aria-selected="true"]{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
.foodex-global-search-result strong,.foodex-global-search-result span{display:block}
.foodex-global-search-result small{display:block;color:var(--foodex-muted);margin-top:2px}
.foodex-global-search-status{margin:6px 2px 0;color:var(--foodex-muted);font-size:var(--foodex-text-xs);min-height:1.25em}
@media(max-width:767px){.foodex-global-search-results{position:fixed;inset-inline:12px;top:112px;max-height:calc(100vh - 132px)}}
</style>
<section class="foodex-global-search"
         data-foodex-global-search
         data-search-url="{{ route('admin.global-search') }}"
         data-loading="{{ __('global_search.loading') }}"
         data-empty="{{ __('global_search.empty') }}"
         data-error="{{ __('global_search.error') }}">
    <label class="foodex-global-search-label" for="{{ $globalSearchId }}">{{ __('global_search.label') }}</label>
    <div class="foodex-global-search-control">
        @include('admin._premium-icon',['name'=>'search'])
        <input id="{{ $globalSearchId }}"
               class="foodex-global-search-input"
               type="search"
               autocomplete="off"
               inputmode="search"
               placeholder="{{ __('global_search.placeholder') }}"
               aria-controls="{{ $globalSearchId }}-results"
               aria-expanded="false"
               data-foodex-global-search-input>
    </div>
    <div id="{{ $globalSearchId }}-results"
         class="foodex-global-search-results"
         role="listbox"
         hidden
         data-foodex-global-search-results></div>
    <p class="foodex-global-search-status" aria-live="polite" data-foodex-global-search-status>{{ __('global_search.hint') }}</p>
</section>
<script src="{{ asset('assets/admin/global-search.js') }}" defer></script>
