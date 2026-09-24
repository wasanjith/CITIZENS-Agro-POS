@extends('layouts.app')

@php $isNew = ! $terminal->exists; @endphp

@section('title', $isNew ? 'Add terminal' : 'Edit '.$terminal->name)

@section('content')
    <x-ui.page-header :title="$isNew ? 'Add terminal' : 'Edit terminal'" />

    <form
        method="POST"
        action="{{ $isNew ? route('admin.terminals.store') : route('admin.terminals.update', $terminal) }}"
        x-data="{ type: @js(old('type', $terminal->type?->value)) }"
    >
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="name" label="Name" :value="$terminal->name" hint="e.g. Counter 1, Main Cashier" required />
                <x-ui.input name="code" label="Code" :value="$terminal->code" hint="Short code, e.g. C1, MAIN" required />
                <x-ui.select
                    name="type"
                    label="Type"
                    :options="collect($types)->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all()"
                    :value="$terminal->type"
                    x-model="type"
                    required
                />
                <div x-show="type === 'counter'">
                    <x-ui.input name="counter_no" label="Counter number" type="number" min="1" max="9" :value="$terminal->counter_no" />
                </div>
                <x-ui.select
                    name="receipt_language"
                    label="Invoice language on this terminal"
                    :options="['si' => 'Sinhala', 'en' => 'English', 'si+en' => 'Sinhala + English']"
                    :value="$terminal->receipt_language"
                    placeholder="Use shop setting"
                />
                <x-ui.checkbox name="is_active" label="Active" :checked="$terminal->is_active" class="sm:col-span-2" />
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="route('admin.terminals.index')">Cancel</x-ui.button>
                <x-ui.button type="submit">{{ $isNew ? 'Create terminal' : 'Save changes' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
