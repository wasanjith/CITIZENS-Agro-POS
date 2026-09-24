@props(['column', 'default' => null])

@php
    $current = request('sort', $default);
    $direction = request('dir', 'asc');
    $isActive = $current === $column;
    $nextDirection = $isActive && $direction === 'asc' ? 'desc' : 'asc';
    $url = request()->fullUrlWithQuery(['sort' => $column, 'dir' => $nextDirection, 'page' => null]);
@endphp

<th {{ $attributes }}>
    <a href="{{ $url }}" class="inline-flex items-center gap-1 hover:text-gray-900">
        {{ $slot }}
        @if ($isActive)
            <span aria-hidden="true">{{ $direction === 'asc' ? '▲' : '▼' }}</span>
        @endif
    </a>
</th>
