@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
    <x-ui.page-header title="Notifications">
        @if (auth()->user()->unreadNotifications()->exists())
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">Mark all as read</x-ui.button>
            </form>
        @endif
    </x-ui.page-header>

    @if ($notifications->isEmpty())
        <x-ui.empty-state title="No notifications" />
    @else
        <ul class="divide-y divide-gray-100 rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            @foreach ($notifications as $notification)
                <li>
                    <a href="{{ route('notifications.open', $notification->id) }}" class="flex items-start gap-3 px-4 py-3 hover:bg-gray-50">
                        <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-brand-600' => $notification->read_at === null, 'bg-transparent' => $notification->read_at !== null])></span>
                        <span class="min-w-0 flex-1">
                            <span @class(['block text-sm', 'font-semibold' => $notification->read_at === null])>{{ $notification->data['title'] ?? 'Notification' }}</span>
                            <span class="block text-sm text-gray-600">{{ $notification->data['message'] ?? '' }}</span>
                        </span>
                        <span class="whitespace-nowrap text-xs text-gray-500">{{ $notification->created_at?->diffForHumans() }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
        <x-ui.pagination :paginator="$notifications" />
    @endif
@endsection
