<x-filament-panels::page>
    @php($data = $this->getRows())
    <div wire:poll.60s class="space-y-6">
        <x-filament::section heading="Задачи по времени" description="Все время указано по Москве. Порядок — по времени суток.">
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                    <thead>
                        <tr style="text-align:left;border-bottom:1px solid rgba(128,128,128,.35)">
                            <th style="padding:.5rem">Время</th>
                            <th style="padding:.5rem">Задача</th>
                            <th style="padding:.5rem">Что делает</th>
                            <th style="padding:.5rem">Как часто</th>
                            <th style="padding:.5rem">Хранение</th>
                            <th style="padding:.5rem">Следующий запуск</th>
                            <th style="padding:.5rem">Последний запуск</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data['rows'] as $r)
                            <tr style="border-bottom:1px solid rgba(128,128,128,.2);{{ $r['enabled'] ? '' : 'opacity:.5' }}">
                                <td style="padding:.5rem;font-weight:600;white-space:nowrap">{{ $r['time'] }}</td>
                                <td style="padding:.5rem">
                                    <div style="font-weight:600">{{ $r['name'] }}</div>
                                    <div style="font-size:.75rem;opacity:.7">{{ $r['kind'] }}{{ $r['enabled'] ? '' : ' · выключено' }}</div>
                                </td>
                                <td style="padding:.5rem">{{ $r['what'] }}</td>
                                <td style="padding:.5rem">{{ $r['rule'] }}</td>
                                <td style="padding:.5rem;white-space:nowrap">{{ $r['keep'] }}</td>
                                <td style="padding:.5rem;white-space:nowrap">
                                    {{ $r['next'] }}
                                    @if ($r['next_note'])<div style="font-size:.75rem;opacity:.7">{{ $r['next_note'] }}</div>@endif
                                </td>
                                <td style="padding:.5rem;white-space:nowrap">
                                    @if ($r['last'])
                                        <x-filament::badge :color="$r['last']['ok'] ? 'success' : ($r['last']['bad'] ? 'danger' : 'warning')">{{ $r['last']['status'] }}</x-filament::badge>
                                        <div style="font-size:.75rem;opacity:.7">{{ $r['last']['at'] }}</div>
                                    @else
                                        <span style="opacity:.6">ещё не было</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" style="padding:1rem;opacity:.7">Задач пока нет</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Служебные процессы" description="Работают постоянно в фоне, настраивать не нужно.">
            <ul style="font-size:.875rem;line-height:1.7">
                @foreach ($data['system'] as $s)
                    <li><strong>{{ $s['name'] }}</strong> — {{ $s['every'] }}: {{ $s['what'] }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    </div>
</x-filament-panels::page>
