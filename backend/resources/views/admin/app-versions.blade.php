<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>App Versions · FOODEX</title></head>
<body>
<main>
    <h1>App Version Policy</h1>
    @if (session('status')) <p role="status">{{ session('status') }}</p> @endif
    @if ($errors->any()) <div role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif
    <form method="post" action="{{ route('admin.app-versions.store') }}">
        @csrf
        <label>App <select name="app" required><option value="customer">Customer</option><option value="driver">Driver</option></select></label>
        <label>Platform <select name="platform" required><option value="android">Android</option><option value="ios">iOS</option></select></label>
        <label>Latest version <input name="latest_version" required></label>
        <label>Minimum supported version <input name="minimum_supported_version" required></label>
        <label><input type="checkbox" name="force_update" value="1"> Force update below latest</label>
        <label>Official store URL <input name="store_url" type="url" required></label>
        <label>Release notes <textarea name="release_notes"></textarea></label>
        <button type="submit">Save policy</button>
    </form>
    <h2>Configured policies</h2>
    @if ($policies->isEmpty()) <p>No app version policies configured.</p>
    @else
        <table><thead><tr><th>App</th><th>Platform</th><th>Latest</th><th>Minimum</th><th>Force</th><th>Store</th></tr></thead>
        <tbody>@foreach ($policies as $policy)<tr><td>{{ $policy->app }}</td><td>{{ $policy->platform }}</td><td>{{ $policy->latest_version }}</td><td>{{ $policy->minimum_supported_version }}</td><td>{{ $policy->force_update ? 'Yes' : 'No' }}</td><td><a href="{{ $policy->store_url }}" rel="noopener">Official store</a></td></tr>@endforeach</tbody></table>
    @endif
</main>
</body></html>
