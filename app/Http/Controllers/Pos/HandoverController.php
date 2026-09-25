<?php

namespace App\Http\Controllers\Pos;

use App\Domain\CashDrawer\Actions\HandoverCashierAction;
use App\Domain\CashDrawer\Actions\ReturnCashierAuthorityAction;
use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Services\DelegationService;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Identity\Support\PermissionCatalogue;
use App\Domain\Sales\Services\LiveCartStore;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\DrawerCountRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Cashier Handover on the main terminal: owner → Manager (with a count and the Manager's
 * PIN), and back again (take back, or count-and-close after a revoke or expiry).
 */
class HandoverController extends Controller
{
    public function __construct(private readonly CurrentTerminal $currentTerminal) {}

    public function create(Request $request, Settings $settings): View
    {
        $closing = CarbonImmutable::parse(today()->format('Y-m-d').' '.$settings->get('pos.closing_time', '18:00'));

        return view('pos.handover.create', [
            'session' => $request->attributes->get('drawerSession'),
            'managers' => User::query()->active()->role(Role::Manager->value)->whereNotNull('pin_hash')->orderBy('name')->get(),
            'permissions' => PermissionCatalogue::delegable(),
            'denominations' => DrawerCalculator::DENOMINATIONS,
            'defaultExpiry' => ($closing->isFuture() ? $closing : now()->addHours(2))->format('Y-m-d\TH:i'),
        ]);
    }

    public function store(DrawerCountRequest $request, HandoverCashierAction $handover): RedirectResponse
    {
        $validated = $request->validate([
            'manager_id' => ['required', 'integer', 'exists:users,id'],
            'manager_pin' => ['required', 'string', 'max:6'],
            'manager_counted' => ['nullable', 'numeric', 'min:0'],
            'expires_at' => ['required', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(PermissionCatalogue::delegable())],
        ]);

        $owner = $request->user();
        $manager = User::query()->findOrFail($validated['manager_id']);

        $result = $handover->handle(
            $owner,
            $this->currentTerminal->get(),
            $request->attributes->get('drawerSession'),
            $request->denominations(),
            $manager,
            $validated['manager_pin'],
            isset($validated['manager_counted']) ? (string) $validated['manager_counted'] : null,
            CarbonImmutable::parse($validated['expires_at']),
            array_values($validated['permissions']),
            $validated['reason'] ?? null,
        );

        $this->switchUser($request, $manager);

        return redirect()->route('pos.cashier')
            ->with('success', "{$manager->name} now holds cashier authority until ".$result['delegation']->expires_at->format('H:i').'. Drawer opened with Rs. '.number_format((float) $result['opened']->opening_float, 2).'.')
            ->with('print_url', route('pos.drawer.report', ['drawerSession' => $result['closed'], 'print' => 1]));
    }

    public function returnForm(Request $request): View|RedirectResponse
    {
        $session = $this->openSession();

        if ($session === null) {
            return redirect()->route('pos.drawer.show');
        }

        $user = $request->user();
        abort_unless($session->holder_user_id === $user->id || $user->can('drawer.handover'), 403);

        return view('pos.handover.return', [
            'session' => $session->load(['holder', 'delegation']),
            'isHolder' => $session->holder_user_id === $user->id,
            'owners' => User::query()->active()->role(Role::SuperAdmin->value)->whereKeyNot($session->holder_user_id)->orderBy('name')->get(),
            'denominations' => DrawerCalculator::DENOMINATIONS,
            'authorityEnded' => ! $user->can('pos.settle'),
        ]);
    }

    public function returnStore(DrawerCountRequest $request, ReturnCashierAuthorityAction $return): RedirectResponse
    {
        $session = $this->openSession();
        abort_if($session === null, 404, 'No drawer is open on this terminal.');

        $validated = $request->validate([
            'new_holder_id' => ['nullable', 'integer', 'exists:users,id'],
            'new_holder_pin' => ['nullable', 'string', 'max:6'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $newHolder = isset($validated['new_holder_id']) ? User::query()->find($validated['new_holder_id']) : null;

        if ($newHolder !== null && ! $newHolder->is($user) && blank($validated['new_holder_pin'] ?? null)) {
            throw ValidationException::withMessages(['new_holder_pin' => "{$newHolder->name} must enter their PIN."]);
        }

        $result = $return->handle($user, $this->currentTerminal->get(), $session, $request->denominations(), $newHolder, $validated['new_holder_pin'] ?? null, $validated['note'] ?? null);
        $slip = route('pos.drawer.report', ['drawerSession' => $result['closed'], 'print' => 1]);

        if ($result['opened'] !== null) {
            if (! $newHolder->is($user)) {
                $this->switchUser($request, $newHolder);
            }

            return redirect()->route('pos.cashier')
                ->with('success', "{$newHolder->name} holds cashier authority again. Variance on the returned drawer: Rs. ".number_format((float) $result['closed']->variance, 2).'.')
                ->with('print_url', $slip);
        }

        return redirect()->route('pos.drawer.show')
            ->with('success', 'Drawer counted and closed. Variance Rs. '.number_format((float) $result['closed']->variance, 2).'. The owner opens the drawer when they take over.')
            ->with('print_url', $slip);
    }

    /**
     * Shown when a delegate's authority was revoked or expired while they hold the drawer.
     */
    public function authorityEnded(Request $request): View|RedirectResponse
    {
        $session = $this->openSession();

        if ($session === null || $session->holder_user_id !== $request->user()->id) {
            return redirect()->route('pos.drawer.show');
        }

        return view('pos.authority-ended', ['session' => $session->load('delegation.revokedBy')]);
    }

    private function openSession(): ?DrawerSession
    {
        return DrawerSession::query()->where('terminal_id', $this->currentTerminal->get()->id)->open()->with('holder')->first();
    }

    /**
     * Sign the terminal over to the next cashier (their PIN was checked in the action).
     */
    private function switchUser(Request $request, User $user): void
    {
        $terminal = $this->currentTerminal->get();
        app(LiveCartStore::class)->signOff($terminal->id);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        app(DelegationService::class)->flush();
    }
}
