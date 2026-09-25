@extends('layouts.pos')

@section('title', $terminal->displayName())
@section('pos-main-class', 'overflow-hidden')

@section('content')
<div
    x-data="posCounter(@js($config))"
    @keydown.window="keydown($event)"
    class="grid h-full grid-cols-1 gap-3 p-3 lg:grid-cols-12"
>
    {{-- Hotkeys --}}
    <span class="hidden" x-hotkey.f2="focusSearch()"></span>
    <span class="hidden" x-hotkey.f4="openCustomer()"></span>
    <span class="hidden" x-hotkey.f6="openTender()"></span>
    <span class="hidden" x-hotkey.f7="quote()"></span>
    <span class="hidden" x-hotkey.f8="hold()"></span>
    <span class="hidden" x-hotkey.f9="print()"></span>
    <span class="hidden" x-hotkey.f10="reprintLast()"></span>
    <span class="hidden" x-hotkey.escape="showInvoices || showHolds || showCustomer || showQuotations ? (showInvoices = showHolds = showCustomer = showQuotations = false, focusSearch()) : (query ? (query = '', results = []) : clearCart())"></span>

    {{-- ============================== Left: search and quick grids ============================== --}}
    <section class="flex min-h-0 flex-col rounded-lg bg-white shadow-sm ring-1 ring-gray-200 lg:col-span-5">
        <div class="border-b border-gray-200 p-3">
            <label for="pos-search" class="sr-only">Search products</label>
            <div class="relative">
                <input
                    id="pos-search"
                    x-ref="search"
                    x-model="query"
                    @input.debounce.80ms="search()"
                    @keydown.arrow-down.prevent="moveHighlight(1)"
                    @keydown.arrow-up.prevent="moveHighlight(-1)"
                    @keydown.enter.prevent="chooseHighlighted()"
                    type="search"
                    autocomplete="off"
                    placeholder="Code, name, සිංහල, 5*urea … (F2)"
                    class="block w-full rounded-md border-gray-300 py-3 pl-3 pr-10 text-lg focus:border-brand-500 focus:ring-brand-500"
                >
                <span x-show="searching" class="absolute right-3 top-3.5 text-xs text-gray-400">…</span>
            </div>
            <div class="mt-2 flex flex-wrap gap-1 text-xs font-medium">
                <template x-for="item in [{ key: 'search', label: 'Results' }, { key: 'favorites', label: 'Favourites' }, { key: 'recent', label: 'Recent' }, { key: 'category', label: 'Categories' }]" :key="item.key">
                    <button type="button" @click="showTab(item.key)" :class="tab === item.key ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="rounded-md px-3 py-1.5" x-text="item.label"></button>
                </template>
            </div>
            <div x-show="tab === 'category'" x-cloak class="mt-2 flex flex-wrap gap-1">
                <template x-for="category in config.categories" :key="category.id">
                    <button type="button" @click="showTab('category', category.id)" :class="categoryId === category.id ? 'bg-brand-100 text-brand-800 ring-brand-300' : 'bg-white text-gray-700 ring-gray-200'" class="rounded-md px-2.5 py-1.5 text-xs ring-1">
                        <span x-text="category.name"></span>
                        <span class="font-sinhala text-gray-500" x-text="category.name_si ? ' · ' + category.name_si : ''"></span>
                    </button>
                </template>
            </div>
        </div>

        <ul class="min-h-0 flex-1 divide-y divide-gray-100 overflow-y-auto" role="listbox" aria-label="Products">
            <template x-for="(item, index) in results" :key="item.key">
                <li
                    :id="`result-${index}`"
                    role="option"
                    :aria-selected="index === highlighted"
                    @click="add(item, item.qty)"
                    @mouseenter="highlighted = index"
                    :class="index === highlighted ? 'bg-brand-50' : ''"
                    class="cursor-pointer px-3 py-2"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <span class="font-mono text-xs font-semibold text-brand-700" x-text="item.short_code"></span>
                            <span class="font-medium" x-text="item.name"></span>
                            <p class="truncate font-sinhala text-sm text-gray-600" x-text="item.name_si"></p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="font-semibold tabular" x-text="item.price !== null ? 'Rs. ' + money(item.price) : 'No price'"></p>
                            <p class="text-xs text-gray-500" x-text="item.unit ? '/ ' + item.unit.symbol : ''"></p>
                        </div>
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-1 text-xs">
                        <span :class="stockColour(item)" class="rounded px-1.5 py-0.5 font-medium tabular" x-text="stockText(item)"></span>
                        <template x-for="unit in item.units.filter(u => u.price !== null && u.id !== item.unit?.id)" :key="unit.id">
                            <button type="button" @click.stop="add({ ...item, unit }, item.qty)" class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-700 hover:bg-gray-200">
                                <span x-text="unit.symbol"></span> <span class="tabular" x-text="money(unit.price)"></span>
                            </button>
                        </template>
                        <span x-show="item.expiry" class="rounded bg-sky-50 px-1.5 py-0.5 text-sky-800" x-text="'Exp ' + item.expiry"></span>
                        <span x-show="item.qty" class="rounded bg-violet-50 px-1.5 py-0.5 text-violet-800" x-text="'× ' + item.qty"></span>
                    </div>
                </li>
            </template>
            <li x-show="results.length === 0" class="px-3 py-8 text-center text-sm text-gray-500">
                <span x-show="tab === 'search'">Type a code or name. Enter adds the highlighted item. <kbd class="rounded bg-gray-100 px-1">5*urea</kbd> adds 5.</span>
                <span x-show="tab !== 'search'" x-cloak>Nothing here yet.</span>
            </li>
        </ul>
    </section>

    {{-- ============================== Right: bill ============================== --}}
    <section class="flex min-h-0 flex-col gap-3 lg:col-span-7">
        {{-- Messages --}}
        <div class="space-y-2" aria-live="polite">
            <template x-for="item in voidRestores" :key="item.sale_id">
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-red-50 p-3 text-sm text-red-800 ring-1 ring-red-200">
                    <span>Invoice <strong x-text="item.invoice_no"></strong> (Rs. <span x-text="money(item.total)"></span>) was voided<span x-text="item.reason ? ': ' + item.reason : ''"></span>. Restore the bill?</span>
                    <span class="flex gap-2">
                        <x-ui.button size="sm" @click="restoreVoided(item)">Restore bill</x-ui.button>
                        <x-ui.button size="sm" variant="secondary" @click="dismissVoided(item)">Dismiss</x-ui.button>
                    </span>
                </div>
            </template>
            <div x-show="error" x-cloak class="flex items-start justify-between gap-2 rounded-md bg-red-50 p-3 text-sm text-red-800 ring-1 ring-red-200" role="alert">
                <span x-text="error"></span>
                <button type="button" @click="error = null" class="text-lg leading-none" aria-label="Dismiss">&times;</button>
            </div>
            <div x-show="printWarning" x-cloak class="flex items-center justify-between gap-2 rounded-md bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200" role="alert">
                <span x-text="printWarning?.message"></span>
                <x-ui.button size="sm" variant="secondary" @click="reprint(printWarning.sale)" x-show="config.can_reprint">Reprint</x-ui.button>
            </div>
            <div x-show="notice && !error" x-cloak class="flex items-start justify-between gap-2 rounded-md bg-sky-50 p-2 text-sm text-sky-800 ring-1 ring-sky-200" x-init="$watch('notice', v => v && setTimeout(() => notice = null, 6000))">
                <span x-text="notice"></span>
                <button type="button" @click="notice = null" class="leading-none" aria-label="Dismiss">&times;</button>
            </div>
            <div x-show="printed && cart.lines.length === 0" x-cloak class="rounded-md bg-brand-50 p-3 ring-1 ring-brand-200">
                <p class="text-sm text-brand-800">Printed <strong x-text="printed?.invoice_no"></strong>. Take the invoice and the money to the cashier.</p>
                <div class="mt-1 flex flex-wrap items-baseline gap-x-6 text-brand-900">
                    <span>Total <strong class="text-xl tabular" x-text="money(printed?.total)"></strong></span>
                    <span x-show="printed?.payment_method === 'cash'">Paid <strong class="text-xl tabular" x-text="money(printed?.tendered)"></strong></span>
                    <span x-show="printed?.payment_method === 'cash'">Balance <strong class="text-3xl tabular" x-text="money(printed?.change_due)"></strong></span>
                    <span x-show="printed?.payment_method !== 'cash'" x-text="printed?.payment_method_label + ': confirm at the cashier'"></span>
                </div>
            </div>
        </div>

        {{-- Cart --}}
        <div class="flex min-h-0 flex-1 flex-col rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="flex items-center justify-between gap-2 border-b border-gray-200 px-3 py-2 text-sm">
                <span class="font-semibold">Bill <span class="text-gray-500" x-text="`(${cart.lines.length} ${cart.lines.length === 1 ? 'item' : 'items'})`"></span></span>
                <span class="flex items-center gap-2">
                    <label class="sr-only" for="price-list">Price list</label>
                    <select id="price-list" x-model.number="cart.price_list_id" @change="changed()" class="rounded-md border-gray-300 py-1 text-xs">
                        <template x-for="list in config.price_lists" :key="list.id">
                            <option :value="list.id" x-text="list.name" :selected="list.id === cart.price_list_id"></option>
                        </template>
                    </select>
                    <template x-if="!customer">
                        <button type="button" @click="openCustomer()" class="rounded-md bg-gray-100 px-2 py-1 text-xs text-gray-700 hover:bg-gray-200">Customer (F4)</button>
                    </template>
                    <template x-if="customer">
                        <span class="flex items-center gap-1 rounded-md bg-violet-50 px-2 py-1 text-xs text-violet-900 ring-1 ring-violet-200">
                            <button type="button" @click="openCustomer()" class="font-semibold" x-text="customer.name"></button>
                            <span x-show="customer.balance !== undefined && Number(customer.balance) > 0" class="tabular" x-text="'owes ' + money(customer.balance)"></span>
                            <span x-show="Number(customer.overdue) > 0" class="font-semibold text-red-700">overdue</span>
                            <button type="button" @click="clearCustomer()" class="ml-1 leading-none text-violet-500 hover:text-violet-800" aria-label="Remove customer">&times;</button>
                        </span>
                    </template>
                    <span x-show="cart.quotation_id" x-cloak class="rounded-md bg-sky-50 px-2 py-1 text-xs text-sky-800 ring-1 ring-sky-200">From quotation</span>
                    <span x-show="syncError" x-cloak class="text-xs text-red-600" x-text="syncError"></span>
                    <span x-show="!syncError" class="size-2 rounded-full" :class="live ? 'bg-brand-500' : 'bg-amber-400'" :title="live ? 'Live' : 'Live Billing by polling'"></span>
                </span>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto">
                <table class="min-w-full text-sm">
                    <thead class="sticky top-0 bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                        <tr>
                            <th class="px-3 py-2">Item</th>
                            <th class="px-2 py-2">Qty</th>
                            <th class="px-2 py-2 text-right">Price</th>
                            <th class="px-2 py-2 text-right">Discount</th>
                            <th class="px-3 py-2 text-right">Amount</th>
                            <th class="px-1 py-2"><span class="sr-only">Remove</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="(line, index) in cart.lines" :key="line.key">
                            <tr @click="selected = index" :class="selected === index ? 'bg-brand-50/60' : ''">
                                <td class="px-3 py-2">
                                    <span class="font-mono text-xs font-semibold text-brand-700" x-text="line.short_code"></span>
                                    <span class="font-medium" x-text="line.name"></span>
                                    <p class="font-sinhala text-xs text-gray-600" x-text="line.name_si"></p>
                                    <p x-show="line.expiry" class="text-xs text-sky-700" x-text="'Exp ' + line.expiry"></p>
                                </td>
                                <td class="px-2 py-2">
                                    <div class="flex items-center gap-1">
                                        <button type="button" @click.stop="step(index, -1)" class="size-7 rounded bg-gray-100 text-lg leading-none hover:bg-gray-200" aria-label="Less">−</button>
                                        <input type="text" inputmode="decimal" x-model="line.qty" @input.debounce.300ms="changed()" @focus="$el.select()" class="w-16 rounded border-gray-300 px-1 py-1 text-center text-sm tabular" :aria-label="'Quantity of ' + line.name">
                                        <button type="button" @click.stop="step(index, 1)" class="size-7 rounded bg-gray-100 text-lg leading-none hover:bg-gray-200" aria-label="More">+</button>
                                        <select @change="setUnit(line, $event.target.value)" class="rounded border-gray-300 py-1 pl-1 pr-6 text-xs" :aria-label="'Unit of ' + line.name">
                                            <template x-for="unit in line.units" :key="unit.id">
                                                <option :value="unit.id" :selected="unit.id === line.unit_id" x-text="unit.symbol"></option>
                                            </template>
                                        </select>
                                    </div>
                                </td>
                                <td class="px-2 py-2 text-right tabular" x-text="money(unitOf(line).price)"></td>
                                <td class="px-2 py-2 text-right">
                                    <input type="text" inputmode="decimal" x-model="line.discount" @input.debounce.400ms="discountChanged(line)" placeholder="0" class="w-20 rounded border-gray-300 px-1 py-1 text-right text-sm tabular" :aria-label="'Discount on ' + line.name">
                                    <template x-if="lineNeedsApproval(line)">
                                        <div class="mt-1 text-xs">
                                            <template x-if="pendingFor('line', line)?.status === 'pending'"><span class="text-amber-700">Waiting for approval…</span></template>
                                            <template x-if="pendingFor('line', line)?.status !== 'pending'">
                                                <button type="button" @click.stop="askApproval('line', line)" class="font-medium text-amber-800 underline">Over limit: ask cashier</button>
                                            </template>
                                        </div>
                                    </template>
                                    <p x-show="line.approval_request_id" class="text-xs text-brand-700">Approved</p>
                                </td>
                                <td class="px-3 py-2 text-right font-semibold tabular" x-text="money(lineTotal(line))"></td>
                                <td class="px-1 py-2">
                                    <button type="button" @click.stop="remove(index)" class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600" :aria-label="'Remove ' + line.name">&times;</button>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="cart.lines.length === 0">
                            <td colspan="6" class="px-3 py-10 text-center text-gray-500">The bill is empty. Search on the left and press Enter.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="grid grid-cols-2 gap-3 border-t border-gray-200 p-3 text-sm sm:grid-cols-3">
                <div class="space-y-1">
                    <div class="flex justify-between"><span class="text-gray-500">Subtotal</span><span class="tabular" x-text="money(subtotal)"></span></div>
                    <div class="flex justify-between"><span class="text-gray-500">Line discounts</span><span class="tabular" x-text="'-' + money(lineDiscounts)"></span></div>
                    <div class="flex items-center justify-between gap-2">
                        <label for="bill-discount" class="text-gray-500">Bill discount</label>
                        <input id="bill-discount" type="text" inputmode="decimal" x-model="cart.bill_discount" @input.debounce.400ms="discountChanged()" placeholder="0" class="w-24 rounded border-gray-300 px-1 py-0.5 text-right tabular">
                    </div>
                    <template x-if="billNeedsApproval">
                        <p class="text-right text-xs">
                            <template x-if="pendingFor('bill')?.status === 'pending'"><span class="text-amber-700">Waiting for approval…</span></template>
                            <template x-if="pendingFor('bill')?.status !== 'pending'"><button type="button" @click="askApproval('bill')" class="font-medium text-amber-800 underline">Over limit: ask cashier</button></template>
                        </p>
                    </template>
                    <p class="text-xs text-gray-400" x-show="config.max_discount_percent !== null" x-text="'Your limit: ' + config.max_discount_percent + ' %'"></p>
                </div>
                <div class="col-span-1 flex flex-col items-end justify-center rounded-md bg-gray-900 px-4 py-2 text-white sm:col-span-2">
                    <span class="text-xs uppercase tracking-wide text-gray-300">Total · එකතුව</span>
                    <span class="text-5xl font-bold tabular" x-text="money(total)"></span>
                </div>
            </div>
        </div>

        {{-- Tender panel (F6) and actions --}}
        <div class="rounded-lg bg-white p-3 shadow-sm ring-1 ring-gray-200">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Payment</span>
                <template x-for="method in Object.keys(config.methods)" :key="method">
                    <button type="button" @click="setMethod(method)" :class="cart.payment_method === method ? 'bg-brand-600 text-white' : (method === 'credit' && !cart.customer_id ? 'bg-gray-50 text-gray-400' : 'bg-gray-100 text-gray-700 hover:bg-gray-200')" class="rounded-md px-3 py-1.5 text-sm font-medium" x-text="config.methods[method]" :title="method === 'credit' && !cart.customer_id ? 'Choose the customer first (F4)' : ''"></button>
                </template>
                <span x-show="!isCash && !isCredit" x-cloak class="text-xs text-amber-700">Confirm the reference at the cashier.</span>
            </div>

            <div x-show="isCredit" x-cloak class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-md bg-violet-50 px-4 py-3 text-violet-900 ring-1 ring-violet-200">
                <div class="text-sm">
                    <p class="font-semibold">Credit sale · ණයට</p>
                    <p x-show="customer" x-text="customer ? customer.name + ' · ' + (customer.phone ?? '') : ''"></p>
                    <p class="text-xs">The invoice prints marked CREDIT. The cashier checks the credit limit.</p>
                </div>
                <dl x-show="customer && customer.credit_limit !== undefined" class="grid grid-cols-2 gap-x-3 text-sm">
                    <dt>Owes now</dt><dd class="text-right tabular" x-text="money(customer?.balance)"></dd>
                    <dt>Available</dt><dd class="text-right font-semibold tabular" :class="Number(customer?.available_credit) < total ? 'text-red-700' : ''" x-text="money(customer?.available_credit)"></dd>
                </dl>
                <p x-show="customer && Number(customer.available_credit) < total" class="w-full text-xs font-semibold text-red-700">This bill is over the customer's credit limit. Only the owner can allow it at the cashier.</p>
            </div>

            <div x-show="isCash" class="mt-3 grid gap-3 sm:grid-cols-3">
                <div>
                    <label for="tendered" class="text-xs font-medium text-gray-600">Amount tendered (F6)</label>
                    <input id="tendered" x-ref="tendered" type="text" inputmode="decimal" x-model="cart.tendered" @input.debounce.300ms="changed()" @keydown.enter.prevent="print()" @focus="tender = true" class="mt-1 block w-full rounded-md border-gray-300 py-2 text-right text-2xl font-semibold tabular focus:border-brand-500 focus:ring-brand-500" placeholder="0.00">
                    <div class="mt-2 flex flex-wrap gap-1">
                        <template x-for="amount in quickAmounts()" :key="amount">
                            <button type="button" @click="setTendered(amount)" class="rounded bg-gray-100 px-2 py-1 text-xs font-medium tabular hover:bg-gray-200" x-text="amount === total ? 'Exact' : money(amount)"></button>
                        </template>
                    </div>
                </div>
                <div class="flex flex-col items-end justify-center rounded-md px-4 py-2 sm:col-span-2" :class="balance !== null && balance < 0 ? 'bg-red-50 text-red-800' : 'bg-brand-50 text-brand-900'">
                    <span class="text-xs uppercase tracking-wide">Balance · ඉතිරිය</span>
                    <span class="text-5xl font-bold tabular" x-text="balance === null ? '—' : money(balance)"></span>
                    <span x-show="balance !== null && balance < 0" class="text-xs">Tendered is less than the total.</span>
                </div>
            </div>

            <div class="mt-3 flex items-center justify-between gap-2">
                <div class="flex min-w-0 flex-wrap gap-2">
                    <x-ui.button variant="secondary" @click="hold()">Hold / Recall (F8)</x-ui.button>
                    <x-ui.button variant="secondary" @click="quote()" x-bind:disabled="quoting"><span x-text="cart.lines.length ? 'Quotation (F7)' : 'Quotations (F7)'"></span></x-ui.button>
                    <x-ui.button variant="secondary" @click="openInvoices()">Last invoices</x-ui.button>
                    <x-ui.button variant="secondary" @click="reprintLast()" x-show="config.can_reprint">Reprint last (F10)</x-ui.button>
                    <x-ui.button variant="ghost" @click="clearCart()">Clear (Esc)</x-ui.button>
                </div>
                <button type="button" @click="print()" :disabled="printing" :class="blocked ? 'bg-gray-300 text-gray-600' : 'bg-brand-600 text-white hover:bg-brand-700'" class="shrink-0 rounded-lg px-8 py-4 text-xl font-bold shadow-sm disabled:opacity-60" :title="blocked ?? ''">
                    <span x-show="!printing">Print invoice (F9)</span>
                    <span x-show="printing" x-cloak>Printing…</span>
                </button>
            </div>
            <p x-show="blocked && cart.lines.length" x-cloak class="mt-1 text-right text-xs text-gray-500" x-text="blocked"></p>
        </div>
    </section>

    {{-- ============================== Last invoices ============================== --}}
    <div x-show="showInvoices" x-cloak class="fixed inset-0 z-40 flex justify-end bg-gray-900/40" @click.self="showInvoices = false">
        <aside class="flex h-full w-full max-w-lg flex-col bg-white shadow-xl" x-trap.inert="showInvoices">
            <header class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <h2 class="font-semibold">Today's invoices · {{ $terminal->displayName() }}</h2>
                <button type="button" @click="showInvoices = false" class="text-2xl leading-none text-gray-500" aria-label="Close">&times;</button>
            </header>
            <ul class="min-h-0 flex-1 divide-y divide-gray-100 overflow-y-auto">
                <template x-for="sale in invoices" :key="sale.id">
                    <li class="flex items-center justify-between gap-3 px-4 py-2 text-sm">
                        <div>
                            <p class="font-medium" x-text="sale.invoice_no"></p>
                            <p class="text-xs text-gray-500"><span x-text="new Date(sale.invoiced_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })"></span> · <span x-text="sale.staff"></span> · <span x-text="sale.lines + ' lines'"></span> <span x-show="sale.print_count > 1" x-text="'· printed ' + sale.print_count + '×'"></span></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="font-semibold tabular" x-text="money(sale.total)"></span>
                            <span class="rounded px-1.5 py-0.5 text-xs" :class="{ 'bg-amber-100 text-amber-900': sale.status === 'invoiced', 'bg-brand-100 text-brand-800': sale.status === 'settled', 'bg-red-100 text-red-800': sale.status === 'void' }" x-text="sale.status_label"></span>
                            <x-ui.button size="sm" variant="secondary" @click="reprint(sale)" x-show="config.can_reprint && sale.status !== 'void'">Reprint</x-ui.button>
                        </div>
                    </li>
                </template>
                <li x-show="invoices.length === 0" class="px-4 py-8 text-center text-sm text-gray-500">No invoices yet today.</li>
            </ul>
        </aside>
    </div>

    {{-- ============================== Customer (F4) ============================== --}}
    <div x-show="showCustomer" x-cloak class="fixed inset-0 z-40 flex items-start justify-center bg-gray-900/40 p-4" @click.self="showCustomer = false">
        <div class="mt-12 flex max-h-[80vh] w-full max-w-2xl flex-col rounded-lg bg-white shadow-xl" x-trap.inert="showCustomer">
            <header class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <h2 class="font-semibold">Customer</h2>
                <button type="button" @click="showCustomer = false" class="text-2xl leading-none text-gray-500" aria-label="Close">&times;</button>
            </header>

            <div class="border-b border-gray-200 p-3" x-show="!quickAdd">
                <label for="customer-search" class="sr-only">Find a customer</label>
                <input id="customer-search" x-ref="customerSearch" x-model="customerQuery" @input.debounce.250ms="searchCustomers()" @keydown.enter.prevent="customerResults.length === 1 && selectCustomer(customerResults[0])" type="search" autocomplete="off" placeholder="Phone, name, NIC or village" class="block w-full rounded-md border-gray-300 text-lg">
            </div>

            <ul class="min-h-0 flex-1 divide-y divide-gray-100 overflow-y-auto" x-show="!quickAdd">
                <template x-for="row in customerResults" :key="row.id">
                    <li @click="selectCustomer(row)" class="flex cursor-pointer items-center justify-between gap-3 px-4 py-2 text-sm hover:bg-brand-50">
                        <div class="min-w-0">
                            <p class="font-medium"><span x-text="row.name"></span> <span class="font-sinhala text-gray-500" x-text="row.name_si ?? ''"></span></p>
                            <p class="text-xs text-gray-500" x-text="[row.code, row.phone, row.area, row.nic].filter(Boolean).join(' · ')"></p>
                        </div>
                        <div class="shrink-0 text-right text-xs">
                            <p x-show="Number(row.balance) !== 0" class="tabular" x-text="'Owes ' + money(row.balance)"></p>
                            <p x-show="Number(row.credit_limit) > 0" class="text-gray-500 tabular" x-text="'Credit left ' + money(row.available_credit)"></p>
                            <p x-show="Number(row.overdue) > 0" class="font-semibold text-red-700 tabular" x-text="'Overdue ' + money(row.overdue)"></p>
                        </div>
                    </li>
                </template>
                <li x-show="customerResults.length === 0 && !customerSearching" class="px-4 py-6 text-center text-sm text-gray-500">No customer found.</li>
            </ul>

            <form x-show="quickAdd" x-cloak @submit.prevent="saveQuickAdd()" class="space-y-3 p-4">
                <p class="text-sm text-gray-600">New customer. Credit needs a limit set by the manager.</p>
                <div>
                    <label for="quick-add-name" class="text-sm font-medium text-gray-700">Name</label>
                    <input id="quick-add-name" :value="quickAdd?.name" @input="quickAdd.name = $event.target.value" required maxlength="150" class="mt-1 block w-full rounded-md border-gray-300">
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="quick-add-phone" class="text-sm font-medium text-gray-700">Phone</label>
                        <input id="quick-add-phone" :value="quickAdd?.phone" @input="quickAdd.phone = $event.target.value" required inputmode="tel" maxlength="15" class="mt-1 block w-full rounded-md border-gray-300">
                    </div>
                    <div>
                        <label for="quick-add-area" class="text-sm font-medium text-gray-700">Village</label>
                        <input id="quick-add-area" :value="quickAdd?.area" @input="quickAdd.area = $event.target.value" maxlength="100" class="mt-1 block w-full rounded-md border-gray-300">
                    </div>
                </div>
                <p x-show="quickAdd?.error" class="text-sm text-red-700" x-text="quickAdd?.error"></p>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" @click="quickAdd = null">Back</x-ui.button>
                    <x-ui.button type="submit" x-bind:disabled="quickAdd?.saving">Add and select</x-ui.button>
                </div>
            </form>

            <footer class="flex items-center justify-between gap-2 border-t border-gray-200 px-4 py-3" x-show="!quickAdd">
                <x-ui.button variant="ghost" @click="clearCustomer(); showCustomer = false" x-show="cart.customer_id">No customer (walk-in)</x-ui.button>
                <span></span>
                <x-ui.button variant="secondary" @click="startQuickAdd()" x-show="config.can_add_customer">New customer</x-ui.button>
            </footer>
        </div>
    </div>

    {{-- ============================== Quotations (F7) ============================== --}}
    <div x-show="showQuotations" x-cloak class="fixed inset-0 z-40 flex items-start justify-center bg-gray-900/40 p-4" @click.self="showQuotations = false">
        <div class="mt-12 flex max-h-[80vh] w-full max-w-2xl flex-col rounded-lg bg-white shadow-xl" x-trap.inert="showQuotations">
            <header class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <h2 class="font-semibold">Open quotations</h2>
                <button type="button" @click="showQuotations = false" class="text-2xl leading-none text-gray-500" aria-label="Close">&times;</button>
            </header>
            <div class="border-b border-gray-200 p-3">
                <label for="quotation-search" class="sr-only">Find a quotation</label>
                <input id="quotation-search" x-ref="quotationSearch" x-model="quotationQuery" @input.debounce.250ms="searchQuotations()" type="search" autocomplete="off" placeholder="Number, customer name or phone" class="block w-full rounded-md border-gray-300">
            </div>
            <ul class="min-h-0 flex-1 divide-y divide-gray-100 overflow-y-auto">
                <template x-for="row in quotations" :key="row.id">
                    <li class="flex items-center justify-between gap-3 px-4 py-2 text-sm">
                        <div>
                            <p class="font-medium" x-text="row.number + (row.customer ? ' · ' + row.customer : '')"></p>
                            <p class="text-xs text-gray-500" x-text="new Date(row.created_at).toLocaleDateString() + ' · ' + row.staff + ' · ' + row.lines + ' lines · valid until ' + row.valid_until"></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="font-semibold tabular" x-text="money(row.total)"></span>
                            <x-ui.button size="sm" variant="secondary" @click="reprintQuotation(row)">Reprint</x-ui.button>
                            <x-ui.button size="sm" @click="loadQuotation(row)">Load</x-ui.button>
                        </div>
                    </li>
                </template>
                <li x-show="quotations.length === 0" class="px-4 py-8 text-center text-sm text-gray-500">No open quotations.</li>
            </ul>
        </div>
    </div>

    {{-- ============================== Held bills ============================== --}}
    <div x-show="showHolds" x-cloak class="fixed inset-0 z-40 flex items-start justify-center bg-gray-900/40 p-4" @click.self="showHolds = false">
        <div class="mt-16 w-full max-w-lg rounded-lg bg-white shadow-xl" x-trap.inert="showHolds">
            <header class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <h2 class="font-semibold">Held bills</h2>
                <button type="button" @click="showHolds = false" class="text-2xl leading-none text-gray-500" aria-label="Close">&times;</button>
            </header>
            <ul class="max-h-96 divide-y divide-gray-100 overflow-y-auto">
                <template x-for="hold in holds" :key="hold.id">
                    <li class="flex items-center justify-between gap-3 px-4 py-2 text-sm">
                        <div>
                            <p class="font-medium" x-text="hold.note || 'Held bill'"></p>
                            <p class="text-xs text-gray-500"><span x-text="new Date(hold.held_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })"></span> · <span x-text="hold.staff"></span> · <span x-text="hold.lines + ' lines'"></span></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="font-semibold tabular" x-text="money(hold.total)"></span>
                            <x-ui.button size="sm" @click="recall(hold)">Recall</x-ui.button>
                        </div>
                    </li>
                </template>
                <li x-show="holds.length === 0" class="px-4 py-8 text-center text-sm text-gray-500">No bills on hold.</li>
            </ul>
        </div>
    </div>
</div>
@endsection
