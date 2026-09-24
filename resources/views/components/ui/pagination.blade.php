@props(['paginator'])

@if ($paginator->hasPages())
    <div {{ $attributes->merge(['class' => 'mt-4']) }}>
        {{ $paginator->links() }}
    </div>
@endif
