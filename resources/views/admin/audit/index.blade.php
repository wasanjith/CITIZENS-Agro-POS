@extends('layouts.app')

@section('title', 'Audit log')

@section('content')
    <x-ui.page-header title="Audit log" description="Every sign-in and every change to users, terminals, printers, settings and delegations." />

    <x-ui.filter-bar :action="route('admin.audit.index')" :search="false" class="mb-4">
        <x-ui.select name="user" :options="$users->pluck('name', 'id')->all()" :value="$filters['user'] ?? null" placeholder="All users" />
        <x-ui.select name="subject" :options="$subjectTypes->mapWithKeys(fn ($type) => [$type => class_basename($type)])->all()" :value="$filters['subject'] ?? null" placeholder="All records" />
        <x-ui.select name="event" :options="$events->mapWithKeys(fn ($event) => [$event => $event])->all()" :value="$filters['event'] ?? null" placeholder="All events" />
        <x-ui.date-input name="from" :value="$filters['from'] ?? null" />
        <x-ui.date-input name="to" :value="$filters['to'] ?? null" />
    </x-ui.filter-bar>

    @if ($activities->isEmpty())
        <x-ui.empty-state title="No activity found" />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>When</th>
                <th>Who</th>
                <th>What</th>
                <th>Record</th>
                <th>Changes</th>
            </x-slot:head>

            @foreach ($activities as $activity)
                <tr class="align-top">
                    <td class="whitespace-nowrap text-gray-600">{{ $activity->created_at->format('Y-m-d H:i:s') }}</td>
                    <td>{{ $activity->causer?->name ?? 'System' }}</td>
                    <td>
                        <p>{{ $activity->description }}</p>
                        @if ($activity->event)
                            <x-ui.badge class="mt-1">{{ $activity->event }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="text-gray-600">
                        @if ($activity->subject_type)
                            {{ class_basename($activity->subject_type) }} #{{ $activity->subject_id }}
                        @endif
                    </td>
                    <td class="max-w-md text-xs">
                        @php
                            $changes = $activity->attribute_changes ?? collect();
                            $new = $changes['attributes'] ?? [];
                            $old = $changes['old'] ?? [];
                        @endphp
                        @foreach ($new as $field => $value)
                            <p>
                                <span class="font-medium">{{ $field }}:</span>
                                @if (array_key_exists($field, $old))
                                    <span class="text-red-700 line-through">{{ is_scalar($old[$field]) || is_null($old[$field]) ? var_export($old[$field], true) : json_encode($old[$field]) }}</span> →
                                @endif
                                <span class="text-brand-800">{{ is_scalar($value) || is_null($value) ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_UNICODE) }}</span>
                            </p>
                        @endforeach
                        @if ($activity->properties?->isNotEmpty())
                            <p class="text-gray-500">{{ json_encode($activity->properties, JSON_UNESCAPED_UNICODE) }}</p>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$activities" />
    @endif
@endsection
