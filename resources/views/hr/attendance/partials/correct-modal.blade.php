{{--
    One correction dialog per page. Open with:
    $dispatch('correct-attendance', { employee_id, name, date, status, clock_in, clock_out })
--}}
@can('update', \App\Domain\HR\Models\Attendance::class)
    <div x-data="{ form: { employee_id: '', name: '', date: '', status: 'present', clock_in: '', clock_out: '' } }"
         x-on:correct-attendance.window="form = { ...form, ...$event.detail }; $dispatch('open-modal', 'correct-attendance')">
        <x-ui.modal name="correct-attendance" title="Correct attendance" max-width="md">
            <form method="POST" action="{{ route('hr.attendance.update') }}" id="correct-attendance-form" class="grid gap-4">
                @csrf
                @method('PUT')
                <input type="hidden" name="employee_id" :value="form.employee_id">
                <input type="hidden" name="date" :value="form.date">
                <p class="text-sm text-gray-600"><span class="font-semibold" x-text="form.name"></span> · <span x-text="form.date"></span></p>

                <div>
                    <label for="correct-status" class="block text-sm font-medium text-gray-700">Status</label>
                    <select id="correct-status" name="status" x-model="form.status" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        @foreach (\App\Domain\HR\Enums\AttendanceStatus::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3" x-show="form.status === 'present' || form.status === 'half_day'">
                    <div>
                        <label for="correct-in" class="block text-sm font-medium text-gray-700">Time in</label>
                        <input id="correct-in" type="time" name="clock_in" x-model="form.clock_in" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label for="correct-out" class="block text-sm font-medium text-gray-700">Time out</label>
                        <input id="correct-out" type="time" name="clock_out" x-model="form.clock_out" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                    </div>
                </div>

                <x-ui.input name="edit_reason" label="Reason for the change" required maxlength="200" placeholder="Forgot to clock out, came without signing in …" />
                <p class="text-xs text-gray-500">Late and overtime minutes are worked out again from the shift. The change is kept in the audit log.</p>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'correct-attendance')">Cancel</x-ui.button>
                <x-ui.button type="submit" form="correct-attendance-form">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
@endcan
