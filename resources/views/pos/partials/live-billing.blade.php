{{--
    Live Billing columns, shared by /pos/cashier (mode "cashier": settle cards) and
    /admin/live-billing (mode "view"). Alpine: liveBilling($config).
--}}
<div x-data="liveBilling(@js($config))" class="flex h-full min-h-0 flex-col gap-3">
    {{-- Top: messages, find, approvals --}}
    <div class="space-y-2">
        <div x-show="error" x-cloak class="flex items-start justify-between gap-2 rounded-md bg-red-50 p-3 text-sm text-red-800 ring-1 ring-red-200" role="alert">
            <span x-text="error"></span>
            <button type="button" @click="error = null" class="text-lg leading-none" aria-label="Dismiss">&times;</button>
        </div>
        <div x-show="notice" x-cloak x-init="$watch('notice', v => v && setTimeout(() => notice = null, 8000))" class="flex items-start justify-between gap-2 rounded-md bg-brand-50 p-3 text-sm text-brand-800 ring-1 ring-brand-200" role="status">
            <span x-text="notice"></span>
            <button type="button" @click="notice = null" class="text-lg leading-none" aria-label="Dismiss">&times;</button>
        </div>

        <template x-for="approval in snapshot.approvals" :key="approval.id">
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-300">
                <span>
                    <strong x-text="approval.terminal"></strong> · <span x-text="approval.requested_by"></span> asks for a
                    <strong x-text="approval.type_label.toLowerCase()"></strong> of <strong class="tabular" x-text="'Rs. ' + money(approval.payload.amount)"></strong>
                    (<span x-text="approval.payload.percent"></span> %) on <span x-text="approval.payload.label"></span>
                </span>
                <span class="flex gap-2" x-show="config.mode === 'cashier' && config.can_approve">
                    <x-ui.button size="sm" @click="decide(approval, true)">Approve</x-ui.button>
                    <x-ui.button size="sm" variant="secondary" @click="decide(approval, false)">Reject</x-ui.button>
                </span>
            </div>
        </template>

        <div class="flex flex-wrap items-center justify-between gap-2" x-show="config.mode === 'cashier'">
            <form @submit.prevent="find()" class="flex items-center gap-2">
                <label for="find-invoice" class="text-sm font-medium text-gray-700">Invoice no.</label>
                <input id="find-invoice" x-ref="find" x-model="findNo" x-hotkey.f2="$el.focus()" inputmode="numeric" autocomplete="off" placeholder="Last digits, e.g. 4512" class="w-48 rounded-md border-gray-300 text-sm" autofocus>
                <x-ui.button type="submit" size="sm">Open (Enter)</x-ui.button>
            </form>
            <span class="flex items-center gap-2 text-xs text-gray-500">
                <span class="size-2 rounded-full" :class="live ? 'bg-brand-500' : 'bg-amber-400'"></span>
                <span x-text="live ? 'Live' : 'Updating every 3 s'"></span>
            </span>
        </div>
    </div>

    {{-- Phones: one column at a time --}}
    <div class="flex gap-1 md:hidden" role="tablist">
        <template x-for="(column, index) in snapshot.counters" :key="column.terminal_id">
            <button type="button" role="tab" @click="activeCounter = index" :aria-selected="activeCounter === index" :class="activeCounter === index ? 'bg-brand-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200'" class="flex-1 rounded-md px-2 py-1.5 text-xs font-semibold">
                <span x-text="column.name"></span>
                <span x-show="column.invoices.length" class="ml-1 rounded-full bg-amber-400 px-1.5 text-gray-900" x-text="column.invoices.length"></span>
            </button>
        </template>
    </div>

    {{-- Counter columns --}}
    <div class="grid min-h-0 flex-1 gap-3 md:grid-cols-3">
        <template x-for="(column, index) in snapshot.counters" :key="column.terminal_id">
            <section class="min-h-0 flex-col overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200" :class="activeCounter === index ? 'flex' : 'hidden md:flex'" :aria-label="column.name">
                <header class="flex items-center justify-between gap-2 border-b border-gray-200 px-3 py-2">
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="size-2.5 shrink-0 rounded-full" :class="isOnline(column) ? 'bg-brand-500' : 'bg-gray-300'" :title="isOnline(column) ? 'Online' : 'Offline'"></span>
                        <h2 class="font-semibold" x-text="column.name"></h2>
                        <span class="truncate text-sm text-gray-500" x-text="online[column.terminal_id] ?? column.user ?? ''"></span>
                    </div>
                    <span class="shrink-0 rounded px-2 py-0.5 text-xs font-semibold uppercase tracking-wide tabular" :class="statusClass(column)" x-text="statusLabel(column)"></span>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    {{-- Live cart --}}
                    <div class="px-3 py-2" x-show="column.cart || removedLines(column.terminal_id).length">
                        <ul class="space-y-1 text-sm">
                            <template x-for="line in column.cart?.lines ?? []" :key="line.key">
                                <li class="flex items-start justify-between gap-2 rounded px-1" :class="isFresh(column.terminal_id, line.key) ? 'bg-sky-50 ring-1 ring-sky-200' : ''">
                                    <span class="min-w-0">
                                        <span class="font-medium" x-text="line.name"></span>
                                        <span class="text-gray-500 tabular" x-text="qty(line.qty) + ' ' + line.unit"></span>
                                        <span x-show="isFresh(column.terminal_id, line.key)" class="text-xs font-semibold text-sky-700">NEW</span>
                                        <span x-show="Number(line.discount) > 0" class="text-xs text-amber-700" x-text="'-' + money(line.discount)"></span>
                                        <span x-show="line.needs_approval" class="text-xs font-semibold text-amber-700">needs approval</span>
                                    </span>
                                    <span class="shrink-0 tabular" x-text="money(line.line_total)"></span>
                                </li>
                            </template>
                            <template x-for="line in removedLines(column.terminal_id)" :key="'x' + line.key">
                                <li class="flex items-start justify-between gap-2 px-1 text-red-600 line-through">
                                    <span><span x-text="line.name"></span> <span x-text="qty(line.qty) + ' ' + line.unit"></span></span>
                                    <span class="text-xs font-semibold no-underline">REMOVED</span>
                                </li>
                            </template>
                        </ul>
                        <dl class="mt-2 space-y-0.5 border-t border-dashed border-gray-200 pt-2 text-sm" x-show="column.cart">
                            <div class="flex justify-between text-base font-bold"><dt>Total</dt><dd class="tabular" x-text="money(column.cart?.total)"></dd></div>
                            <div class="flex justify-between" x-show="column.cart?.tendered !== null && column.cart?.tendered !== undefined"><dt>Tendered</dt><dd class="tabular" x-text="money(column.cart?.tendered)"></dd></div>
                            <div class="flex justify-between" x-show="column.cart?.tendered !== null && column.cart?.tendered !== undefined"><dt>Balance</dt><dd class="tabular" x-text="money(column.cart?.change_due)"></dd></div>
                        </dl>
                    </div>

                    {{-- Invoices waiting for settlement --}}
                    <div class="space-y-2 px-3 py-2" x-show="column.invoices.length">
                        <template x-for="sale in column.invoices" :key="sale.id">
                            <article class="rounded-md p-2 ring-1" :class="late(sale) ? 'bg-red-50 ring-red-300' : 'bg-amber-50 ring-amber-200'">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-semibold" x-text="sale.invoice_no"></span>
                                    <span class="text-xs font-semibold tabular" :class="late(sale) ? 'text-red-700' : 'text-amber-800'" x-text="waited(sale)"></span>
                                </div>
                                <dl class="mt-1 grid grid-cols-2 gap-x-2 text-sm">
                                    <dt class="text-gray-600">Total</dt><dd class="text-right font-bold tabular" x-text="money(sale.total)"></dd>
                                    <template x-if="sale.payment_method === 'cash'">
                                        <dt class="text-gray-600">Paid</dt>
                                    </template>
                                    <template x-if="sale.payment_method === 'cash'">
                                        <dd class="text-right tabular" x-text="money(sale.tendered)"></dd>
                                    </template>
                                    <template x-if="sale.payment_method === 'cash'">
                                        <dt class="text-gray-600">Balance</dt>
                                    </template>
                                    <template x-if="sale.payment_method === 'cash'">
                                        <dd class="text-right font-semibold tabular" x-text="money(sale.change_due)"></dd>
                                    </template>
                                    <template x-if="sale.payment_method !== 'cash'">
                                        <dt class="col-span-2 text-amber-800" x-text="sale.payment_method_label + (sale.payment_method === 'credit' ? ' · check the credit limit' : ' · confirm reference')"></dt>
                                    </template>
                                </dl>
                                <p class="mt-1 text-xs text-gray-500"><span x-text="sale.staff"></span><span x-show="sale.customer" class="font-medium text-gray-700" x-text="' · ' + (sale.customer?.name ?? '')"></span></p>
                                <div class="mt-2 flex gap-2" x-show="config.mode === 'cashier'">
                                    <x-ui.button size="sm" class="flex-1" @click="openSettle(sale)">Settle</x-ui.button>
                                    <x-ui.button size="sm" variant="secondary" class="text-red-700" @click="openVoid(sale)" x-show="config.can_void">Void</x-ui.button>
                                </div>
                            </article>
                        </template>
                    </div>

                    <p x-show="!column.cart && !column.invoices.length && !removedLines(column.terminal_id).length" class="px-3 py-6 text-center text-sm text-gray-400">No bill in progress.</p>
                </div>

                {{-- Activity ticker --}}
                <footer class="max-h-40 shrink-0 overflow-y-auto border-t border-gray-200 bg-gray-50 px-3 py-2">
                    <ul class="space-y-0.5 text-xs">
                        <template x-for="event in column.events" :key="event.id">
                            <li :class="event.alert ? 'font-medium text-red-700' : 'text-gray-600'">
                                <span class="tabular text-gray-400" x-text="event.time"></span> <span x-text="event.text"></span>
                            </li>
                        </template>
                        <li x-show="column.events.length === 0" class="text-gray-400">No activity today.</li>
                    </ul>
                </footer>
            </section>
        </template>
    </div>

    {{-- Direct sales on the main terminal --}}
    <template x-if="snapshot.main && snapshot.main.invoices.length && config.mode === 'cashier'">
        <div class="flex flex-wrap items-center gap-2 rounded-lg bg-white p-2 shadow-sm ring-1 ring-gray-200">
            <span class="text-sm font-semibold">Main terminal</span>
            <template x-for="sale in snapshot.main.invoices" :key="sale.id">
                <x-ui.button size="sm" variant="secondary" @click="openSettle(sale)"><span x-text="sale.invoice_no + ' · ' + money(sale.total)"></span></x-ui.button>
            </template>
        </div>
    </template>

    {{-- Bottom bar --}}
    <footer class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 rounded-lg bg-gray-900 px-4 py-2 text-sm text-gray-100">
        <div class="flex flex-wrap gap-x-4">
            <span class="text-gray-400">Today</span>
            <template x-for="column in snapshot.counters" :key="column.terminal_id">
                <span><span x-text="'C' + column.counter_no"></span> <strong class="tabular" x-text="'Rs. ' + money(column.today.total)"></strong> <span class="text-gray-400" x-text="'(' + column.today.count + ')'"></span></span>
            </template>
        </div>
        <div class="flex flex-wrap gap-x-4">
            <span x-show="snapshot.totals.drawer !== null">Drawer <strong class="tabular" x-text="'Rs. ' + money(snapshot.totals.drawer)"></strong> <span class="text-gray-400" x-text="snapshot.totals.drawer_holder ? '(' + snapshot.totals.drawer_holder + ')' : ''"></span></span>
            <span>Waiting <strong x-text="snapshot.totals.waiting ?? 0"></strong></span>
            <span>Voids today <strong x-text="snapshot.totals.voids ?? 0"></strong></span>
            <span x-show="snapshot.cashier">Cashier <strong x-text="snapshot.cashier?.name"></strong></span>
        </div>
    </footer>

    @if (($config['mode'] ?? 'view') === 'cashier')
        {{-- Settle --}}
        <div x-show="settling" x-cloak class="fixed inset-0 z-50 flex items-start justify-center bg-gray-900/50 p-4" @keydown.escape.window="settling = null">
            <form @submit.prevent="settle()" class="mt-12 w-full max-w-md rounded-lg bg-white shadow-xl" x-trap.inert.noscroll="settling">
                <header class="border-b border-gray-200 px-5 py-4">
                    <h2 class="text-lg font-semibold">Settle <span x-text="settling?.invoice_no"></span></h2>
                    <p class="text-sm text-gray-500"><span x-text="settling?.terminal"></span> · <span x-text="settling?.staff"></span> · waiting <span x-text="waited(settling)"></span></p>
                </header>
                <div class="space-y-3 px-5 py-4">
                    <dl class="grid grid-cols-2 gap-y-1 text-base">
                        <dt>Total</dt><dd class="text-right text-2xl font-bold tabular" x-text="money(settling?.total)"></dd>
                        <template x-if="settleMethod === 'cash'"><dt>Received from counter</dt></template>
                        <template x-if="settleMethod === 'cash'"><dd class="text-right tabular" x-text="money(settling?.tendered)"></dd></template>
                        <template x-if="settleMethod === 'cash'"><dt class="font-semibold">Give back (balance)</dt></template>
                        <template x-if="settleMethod === 'cash'"><dd class="text-right text-2xl font-bold tabular text-brand-700" x-text="money(settling?.change_due)"></dd></template>
                    </dl>
                    <div>
                        <label for="settle-method" class="block text-sm font-medium text-gray-700">Payment method</label>
                        <select id="settle-method" x-model="settleMethod" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                            @foreach ($config['methods'] ?? [] as $value => $label)
                                <option value="{{ $value }}" @if ($value === 'credit') x-bind:disabled="!settling?.customer_id" @endif>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <template x-if="settling?.customer">
                        <div class="rounded-md bg-gray-50 p-3 text-sm ring-1 ring-gray-200">
                            <p><span class="font-semibold" x-text="settling.customer.name"></span> <span class="text-gray-500" x-text="settling.customer.code + (settling.customer.phone ? ' · ' + settling.customer.phone : '')"></span></p>
                            <template x-if="settleCustomer">
                                <dl class="mt-1 grid grid-cols-2 gap-x-2 text-xs text-gray-700">
                                    <dt>Owes now</dt><dd class="text-right tabular" x-text="money(settleCustomer.balance)"></dd>
                                    <dt>Credit limit</dt><dd class="text-right tabular" x-text="money(settleCustomer.credit_limit)"></dd>
                                    <dt>Overdue</dt><dd class="text-right tabular" :class="Number(settleCustomer.overdue) > 0 ? 'font-semibold text-red-700' : ''" x-text="money(settleCustomer.overdue)"></dd>
                                </dl>
                            </template>
                            <div x-show="overLimit" class="mt-2 rounded bg-red-50 p-2 text-xs text-red-800 ring-1 ring-red-200">
                                Over the credit limit by Rs. <span class="tabular" x-text="money(Number(settleCustomer?.balance) + Number(settling?.total) - Number(settleCustomer?.credit_limit))"></span>.
                                <template x-if="config.can_override_credit">
                                    <label class="mt-1 flex items-center gap-2 font-medium"><input type="checkbox" x-model="overrideCredit" class="rounded text-red-600"> Allow it (owner)</label>
                                </template>
                                <template x-if="!config.can_override_credit">
                                    <span class="block font-medium">Only the owner can allow it. Void and bill it as cash, or ask the owner.</span>
                                </template>
                            </div>
                        </div>
                    </template>
                    <div x-show="settleMethod !== 'cash' && settleMethod !== 'credit'">
                        <label for="settle-reference" class="block text-sm font-medium text-gray-700">Reference (slip / transfer / cheque no.)</label>
                        <input id="settle-reference" x-model="settleReference" class="mt-1 block w-full rounded-md border-gray-300 text-sm" autocomplete="off">
                    </div>
                    <p x-show="error" class="text-sm text-red-700" x-text="error"></p>
                </div>
                <footer class="flex justify-end gap-2 rounded-b-lg border-t border-gray-200 bg-gray-50 px-5 py-3">
                    <x-ui.button variant="secondary" @click="settling = null">Cancel (Esc)</x-ui.button>
                    <x-ui.button variant="secondary" class="text-red-700" @click="openVoid(settling)" x-show="config.can_void">Void…</x-ui.button>
                    <x-ui.button type="submit" id="settle-confirm" x-bind:disabled="busy">Settle (Enter)</x-ui.button>
                </footer>
            </form>
        </div>

        {{-- Void --}}
        <div x-show="voiding" x-cloak class="fixed inset-0 z-50 flex items-start justify-center bg-gray-900/50 p-4" @keydown.escape.window="voiding = null">
            <form @submit.prevent="confirmVoid()" class="mt-12 w-full max-w-md rounded-lg bg-white shadow-xl" x-trap.inert.noscroll="voiding">
                <header class="border-b border-gray-200 px-5 py-4">
                    <h2 class="text-lg font-semibold">Void <span x-text="voiding?.invoice_no"></span></h2>
                    <p class="text-sm text-gray-500">The invoice keeps its number and shows as VOID. The counter is offered the bill back.</p>
                </header>
                <div class="px-5 py-4">
                    <label for="void-reason" class="block text-sm font-medium text-gray-700">Reason</label>
                    <input id="void-reason" x-model="voidReason" required maxlength="255" class="mt-1 block w-full rounded-md border-gray-300 text-sm" placeholder="Wrong item, customer left …">
                    <p x-show="error" class="mt-2 text-sm text-red-700" x-text="error"></p>
                </div>
                <footer class="flex justify-end gap-2 rounded-b-lg border-t border-gray-200 bg-gray-50 px-5 py-3">
                    <x-ui.button variant="secondary" @click="voiding = null">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="danger" x-bind:disabled="busy || voidReason.trim() === ''">Void invoice</x-ui.button>
                </footer>
            </form>
        </div>
    @endif
</div>
