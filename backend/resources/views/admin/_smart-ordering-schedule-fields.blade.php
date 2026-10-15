@php
    $prefix = $prefix ?? '';
    $schedule = is_array($schedule ?? null) ? $schedule : [];
    $window = data_get($schedule, 'windows.0', []);
    $enabled = is_array($window) && !empty($window);
    $days = array_map('intval', (array)($window['days'] ?? []));
    $labels = app()->getLocale() === 'ar'
        ? [1=>'الإثنين',2=>'الثلاثاء',3=>'الأربعاء',4=>'الخميس',5=>'الجمعة',6=>'السبت',7=>'الأحد']
        : [1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'];
@endphp
<div class="so-schedule">
    <label class="so-check">
        <input type="checkbox" name="{{ $prefix }}schedule_enabled" value="1" @checked($enabled)>
        <span>{{ app()->getLocale()==='ar' ? 'تفعيل جدول التوفر' : 'Enable availability schedule' }}</span>
    </label>
    <div class="so-days">
        @foreach($labels as $day => $label)
            <label><input type="checkbox" name="{{ $prefix }}schedule_days[]" value="{{ $day }}" @checked(in_array($day, $days, true))> {{ $label }}</label>
        @endforeach
    </div>
    <div class="so-inline">
        <label>{{ app()->getLocale()==='ar' ? 'من' : 'From' }}
            <input class="foodex-control" type="time" name="{{ $prefix }}schedule_start" value="{{ $window['start'] ?? '06:00' }}">
        </label>
        <label>{{ app()->getLocale()==='ar' ? 'إلى' : 'To' }}
            <input class="foodex-control" type="time" name="{{ $prefix }}schedule_end" value="{{ $window['end'] ?? '23:59' }}">
        </label>
    </div>
</div>
