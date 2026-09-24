@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <x-ui.page-header title="Settings" />

    <x-ui.tabs
        class="mb-6"
        :active="$group"
        :tabs="collect($groups)->map(fn ($definition, $key) => ['label' => $definition['title'], 'href' => route('admin.settings.edit', $key)])->all()"
    />

    <form method="POST" action="{{ route('admin.settings.update', $group) }}">
        @csrf
        @method('PUT')

        <x-ui.card :title="$schema['title']" :description="$schema['description']" class="max-w-3xl">
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($schema['fields'] as $key => $field)
                    @php $value = $values[$key] ?? null; @endphp
                    @switch($field['type'])
                        @case('textarea')
                            <x-ui.textarea :name="$key" :label="$field['label']" :value="$value" :hint="$field['hint'] ?? null" class="sm:col-span-2" />
                            @break
                        @case('select')
                            <x-ui.select :name="$key" :label="$field['label']" :options="$field['options']" :value="$value" :hint="$field['hint'] ?? null" />
                            @break
                        @case('checkbox')
                            <x-ui.checkbox :name="$key" :label="$field['label']" :checked="(bool) $value" :hint="$field['hint'] ?? null" class="sm:col-span-2" />
                            @break
                        @default
                            <x-ui.input :name="$key" :label="$field['label']" :type="$field['type']" :value="$value" :hint="$field['hint'] ?? null" step="any" />
                    @endswitch
                @endforeach
            </div>

            <x-slot:footer>
                <x-ui.button type="submit" x-hotkey.ctrl.s="$el.click()">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
