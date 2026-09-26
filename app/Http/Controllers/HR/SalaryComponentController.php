<?php

namespace App\Http\Controllers\HR;

use App\Domain\HR\Enums\SalaryComponentCalc;
use App\Domain\HR\Enums\SalaryComponentType;
use App\Domain\HR\Models\SalaryComponent;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Regular allowances and deductions. Which employee gets which is ticked on the
 * employee form.
 */
class SalaryComponentController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', SalaryComponent::class);

        return view('hr.components.index', [
            'components' => SalaryComponent::query()->withCount('employees')->orderBy('type')->orderBy('name')->get(),
            'types' => SalaryComponentType::options(),
            'calcs' => SalaryComponentCalc::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', SalaryComponent::class);

        $component = SalaryComponent::create([...$this->validated($request), 'is_active' => true]);

        return back()->with('success', "{$component->name} added. Tick it on each employee who gets it.");
    }

    public function update(Request $request, SalaryComponent $salaryComponent): RedirectResponse
    {
        $this->authorize('update', $salaryComponent);

        $salaryComponent->update([...$this->validated($request), 'is_active' => $request->boolean('is_active')]);

        return back()->with('success', "{$salaryComponent->name} saved. Payrolls not yet approved change when recalculated.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::enum(SalaryComponentType::class)],
            'calc' => ['required', Rule::enum(SalaryComponentCalc::class)],
            'value' => ['required', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
        ]);

        return [
            ...$validated,
            'is_epf_applicable' => $validated['type'] === SalaryComponentType::Allowance->value && $request->boolean('is_epf_applicable'),
        ];
    }
}
