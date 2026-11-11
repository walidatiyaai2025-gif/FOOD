<section class="fieldops-card" data-v2-van-movement-log aria-labelledby="v2-van-movement-log-title">
                <h2 id="v2-van-movement-log-title">{{ __('field_operations.movement_log.title') }}</h2>
                <p class="fieldops-muted">{{ __('field_operations.movement_log.description') }}</p>
                <div class="table-wrap"><table class="foodex-ops-grid">
                    <thead><tr>
                        <th scope="col">{{ __('field_operations.movement_log.order') }}</th>
                        <th scope="col">{{ __('field_operations.movement_log.from') }}</th>
                        <th scope="col">{{ __('field_operations.movement_log.to') }}</th>
                        <th scope="col">{{ __('field_operations.movement_log.captured_at') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse($movementRows as $movement)
                            @php($fromKey = 'order_operations.business_labels.'.$movement->from_status)
                            @php($toKey = 'order_operations.business_labels.'.$movement->to_status)
                            <tr data-v2-van-movement-row>
                                <td>{{ $movement->order_number }}</td>
                                <td>{{ \Illuminate\Support\Facades\Lang::has($fromKey) ? __($fromKey) : __('field_operations.movement_log.unknown') }}</td>
                                <td>{{ \Illuminate\Support\Facades\Lang::has($toKey) ? __($toKey) : __('field_operations.movement_log.unknown') }}</td>
                                <td>{{ $movement->captured_at }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><div class="foodex-ops-state">{{ __('field_operations.movement_log.empty') }}</div></td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </section>
