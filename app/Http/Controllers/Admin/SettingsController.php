<?php

namespace App\Http\Controllers\Admin;

use App\Domain\System\Services\Settings;
use App\Domain\System\Support\SettingsSchema;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(string $group, Settings $settings): View
    {
        $groups = SettingsSchema::groups();
        abort_unless(isset($groups[$group]), 404);

        return view('admin.settings.edit', [
            'groups' => $groups,
            'group' => $group,
            'schema' => $groups[$group],
            'values' => $settings->group($group),
        ]);
    }

    public function update(Request $request, string $group, Settings $settings): RedirectResponse
    {
        $groups = SettingsSchema::groups();
        abort_unless(isset($groups[$group]), 404);

        $fields = $groups[$group]['fields'];

        foreach ($fields as $key => $field) {
            if ($field['type'] === 'checkbox') {
                $request->merge([$key => $request->boolean($key)]);
            }
        }

        $validated = $request->validate(array_map(fn (array $field): array => $field['rules'], $fields));

        foreach ($fields as $key => $field) {
            $validated[$key] = match ($field['type']) {
                'checkbox' => (bool) $validated[$key],
                'number' => is_numeric($validated[$key]) ? $validated[$key] + 0 : $validated[$key],
                default => $validated[$key] ?? '',
            };
        }

        $settings->setGroup($group, $validated);

        return redirect()->route('admin.settings.edit', $group)->with('success', $groups[$group]['title'].' saved.');
    }
}
