{{-- Fields of one shift form. Plain inputs (not x-ui.*) so several forms on the page keep their own values after a save. --}}
@php $uid = 'shift-'.($shift->id ?? 'new'); @endphp
<div class="sm:col-span-2">
    <label for="{{ $uid }}-name" class="block text-sm font-medium text-gray-700">Name</label>
    <input id="{{ $uid }}-name" type="text" name="name" value="{{ $shift->name }}" required maxlength="60" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
</div>
<div>
    <label for="{{ $uid }}-start" class="block text-sm font-medium text-gray-700">Starts</label>
    <input id="{{ $uid }}-start" type="time" name="start_time" value="{{ substr((string) $shift->start_time, 0, 5) }}" required class="mt-1 block w-full rounded-md border-gray-300 text-sm">
</div>
<div>
    <label for="{{ $uid }}-end" class="block text-sm font-medium text-gray-700">Ends</label>
    <input id="{{ $uid }}-end" type="time" name="end_time" value="{{ substr((string) $shift->end_time, 0, 5) }}" required class="mt-1 block w-full rounded-md border-gray-300 text-sm">
</div>
<div>
    <label for="{{ $uid }}-grace" class="block text-sm font-medium text-gray-700">Late after (min)</label>
    <input id="{{ $uid }}-grace" type="number" name="grace_minutes" value="{{ $shift->grace_minutes }}" min="0" max="120" required class="mt-1 block w-full rounded-md border-gray-300 text-sm">
</div>
<label class="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" name="is_default" value="1" @checked($shift->is_default) class="rounded border-gray-300 text-brand-600"> Default</label>
<fieldset class="flex flex-wrap gap-3 sm:col-span-6">
    <legend class="sr-only">Working days</legend>
    @foreach (\App\Domain\HR\Models\Shift::DAY_NAMES as $number => $name)
        <label class="flex items-center gap-1 text-sm"><input type="checkbox" name="working_days[]" value="{{ $number }}" @checked(in_array($number, array_map('intval', $shift->working_days ?? []), true)) class="rounded border-gray-300 text-brand-600"> {{ $name }}</label>
    @endforeach
</fieldset>
