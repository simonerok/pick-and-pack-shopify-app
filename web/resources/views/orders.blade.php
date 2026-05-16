@extends('layouts.app')

@section('content')
<main class="w-full min-w-0 px-4 py-8 text-sm relative" x-data="ordersPage()" x-init="init()">
    <div class="flex justify-center mb-6">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 264.57 18.51" width="240">
            <path d="M23.77.36c-5.09 0-9.08 3.98-9.08 9.07s3.98 9.08 9.08 9.08 9.08-3.99 9.08-9.08S28.86.36 23.77.36m0 16.53c-4.33 0-7.36-3.08-7.36-7.49s3.03-7.5 7.36-7.5 7.36 3.08 7.36 7.5-3.03 7.49-7.36 7.49M120.56.64h1.67V18.2h-1.67zM71.08.64h1.67V18.2h-1.67zM113.2 9.18l-.26-.12.24-.16c.72-.48 1.94-1.6 1.94-3.65 0-2.76-2.16-4.61-5.37-4.61h-5.09V18.2h6.01c3.16 0 5.37-1.95 5.37-4.74 0-2-.93-3.4-2.84-4.29m-6.71-6.91h3.38c1.58 0 3.42.8 3.42 3.05 0 1.94-1.25 3.05-3.42 3.05h-3.38zm4.29 14.15h-4.3v-6.35h4.08c1.77 0 3.65 1.11 3.65 3.17s-1.76 3.17-3.43 3.17M192.15 9.18l-.26-.12.24-.16c.73-.48 1.94-1.6 1.94-3.65 0-2.76-2.16-4.61-5.37-4.61h-5.09V18.2h6.01c3.16 0 5.37-1.95 5.37-4.74 0-2-.92-3.4-2.83-4.29m-6.72-6.91h3.38c1.58 0 3.42.8 3.42 3.05 0 1.94-1.25 3.05-3.42 3.05h-3.38zm4.3 14.15h-4.3v-6.35h4.07c1.77 0 3.65 1.11 3.65 3.17s-1.76 3.17-3.42 3.17M78.04.64V18.2h11.11v-1.78h-9.28v-6.35h6.51V8.36h-6.51v-6.1h8.62V.64zM157.6.64V18.2h11.11v-1.78h-9.29v-6.35h6.52V8.36h-6.52v-6.1h8.63V.64zM253.45.64V18.2h11.12v-1.79h-9.29v-6.34h6.51V8.36h-6.51v-6.1h8.64V.64zM6.37 8.26c-2.72-.98-4.09-1.79-4.09-3.55 0-1.62 1.38-2.8 3.27-2.8 1.66 0 3.1 1.09 3.42 2.55h1.84C10.48 2.07 8.3.36 5.54.36S.58 2.17.58 4.59c0 3.19 2.33 4.34 4.83 5.11 2.61.81 4.24 2.28 4.24 3.83 0 2-1.55 3.35-3.87 3.35-1.87 0-3.47-1.26-3.94-3.09H0c.29 2.53 2.94 4.71 5.79 4.71 3.61 0 5.56-2.56 5.56-4.98 0-3.24-2.16-4.27-4.98-5.28M128.09.64V18.2h10.66v-1.78h-8.84V.64zM142.85.64V18.2h10.67v-1.78h-8.84V.64zM42.46.64h-5.09V18.2h1.83v-7.81h4.07c2.56 0 4.56-2.19 4.56-4.98S45.63.64 42.46.64m.12 8.04H39.2V2.26h3.38c1.65 0 3.42 1.01 3.42 3.21 0 1.98-1.31 3.21-3.42 3.21M222.14 3.71l2.62 6.64h-5.19zM221.8 0l-7.05 18.2h1.78l2.41-6.23h6.45l2.46 6.23h1.79L222.47 0zM245.88.64v7.72h-9.84V.64h-1.82V18.2h1.82v-8.13h9.84v8.13h1.83V.64zM63.66.64v7.72h-9.83V.64H52V18.2h1.83v-8.13h9.83v8.13h1.83V.64zM207.23 12.3c.62 1.08 1.05 2.18 1.5 3.35.3.79.63 1.63 1.04 2.54h1.89c-.55-1.1-.93-2.12-1.32-3.1l-.02-.05c-.48-1.24-.92-2.41-1.62-3.6a5.5 5.5 0 0 0-1.07-1.33l-.17-.15.2-.11c1.52-.84 2.47-2.53 2.47-4.43 0-2.81-2.21-4.77-5.37-4.77h-5.09v17.56h1.83V10.4h1.84c2.43 0 3.32.97 3.88 1.91m-5.71-10.05h3.38c1.65 0 3.43 1.01 3.43 3.21 0 2.37-1.77 3.21-3.43 3.21h-3.38z" />
        </svg>
    </div>
    <div class="flex justify-center mb-6">
        <p class="text-slate-500 text-xs mb-4" x-text="activeTab === 'ready-to-pack' ? 'Paid open orders ready to be packed.' : activeTab === 'ready-for-pickup' ? 'Open orders ready for pickup.' : activeTab === 'on-hold' ? 'Orders tagged with On-hold.' : activeTab === 'upcoming' ? 'Open not-paid orders and not fully available orders.' : 'Closed/archived orders from the last 2 months in Shopify.'"></p>
    </div>
    <div class="flex gap-6 mb-4 border-b border-slate-200">
        {{-- Pick & Pack tab deactivated; restore button to re-enable --}}
        {{--
        <button type="button" @click="activeTab = 'pick-and-pack'" :class="activeTab === 'pick-and-pack' ? 'pb-2.5 text-sm transition-colors -mb-px border-b-2 font-semibold text-slate-800 border-slate-800' : 'pb-2.5 text-sm transition-colors -mb-px border-b-2 font-normal text-slate-600 hover:text-slate-800 border-transparent'">Pick & Pack</button>
        --}}
        <button type="button" @click="activeTab = 'ready-to-pack'; loadReadyToPackIfNeeded()" :class="activeTab === 'ready-to-pack' ? 'pb-2.5 text-sm transition-colors -mb-px border-b-2 font-semibold text-slate-800 border-slate-800' : 'pb-2.5 text-sm transition-colors -mb-px border-b-2 font-normal text-slate-600 hover:text-slate-800 border-transparent'">Ready to pack</button>
        <button type="button" @click="activeTab = 'ready-for-pickup'; loadReadyForPickupIfNeeded()" :class="activeTab === 'ready-for-pickup' ? 'pb-2.5 text-sm transition-colors -mb-px border-b-2 font-semibold text-slate-800 border-slate-800' : 'pb-2.5 text-sm transition-colors -mb-px border-b-2 font-normal text-slate-600 hover:text-slate-800 border-transparent'">Ready for pickup</button>
        <button type="button" @click="activeTab = 'on-hold'; loadOnHoldIfNeeded()" :class="activeTab === 'on-hold' ? 'pb-2.5 text-sm transition-colors -mb-px border-b-2 font-semibold text-slate-800 border-slate-800' : 'pb-2.5 text-sm transition-colors -mb-px border-b-2 font-normal text-slate-600 hover:text-slate-800 border-transparent'">On hold</button>
        <button type="button" @click="activeTab = 'upcoming'; loadUpcomingIfNeeded()" :class="activeTab === 'upcoming' ? 'pb-2.5 text-sm transition-colors -mb-px border-b-2 font-semibold text-slate-800 border-slate-800' : 'pb-2.5 text-sm transition-colors -mb-px border-b-2 font-normal text-slate-600 hover:text-slate-800 border-transparent'">Upcoming</button>
        <button type="button" @click="activeTab = 'shipped'; loadShippedIfNeeded()" :class="activeTab === 'shipped' ? 'pb-2.5 text-sm transition-colors -mb-px border-b-2 font-semibold text-slate-800 border-slate-800' : 'pb-2.5 text-sm transition-colors -mb-px border-b-2 font-normal text-slate-600 hover:text-slate-800 border-transparent'">Archived</button>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto md:overflow-visible">
            <table class="w-full text-left orders-table-responsive">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50/80">
                        <th class="w-10 px-2 py-2 text-slate-500" aria-label="Expand"></th>
                        <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Order</th>
                        <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Gift</th>
                        <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Customer</th>
                        <template x-if="activeTab !== 'shipped'">
                            <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Ship date</th>
                        </template>
                        <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Delivery method</th>
                        <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Financial</th>
                        <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Fulfillment</th>
                        <template x-if="activeTab !== 'shipped'">
                            <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Availability</th>
                        </template>
                        <template x-if="activeTab !== 'shipped'">
                            <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 w-20">Available %</th>
                        </template>
                        <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 ">Actions</th>
                    </tr>
                </thead>
                {{-- 1. Pick & Pack tab (deactivated; uncomment block + Alpine getters + tab button to restore) --}}
                {{--
                <tbody>
                    <tr x-show="loading && activeTab === 'pick-and-pack'">
                        <td :colspan="activeTab === 'shipped' ? 7 : 10" class="px-4 py-6 text-center text-slate-500 text-xs">
                            <span class="inline-block w-5 h-5 border-2 border-slate-400 border-t-transparent rounded-full animate-spin align-middle mr-2"></span>
                            Loading orders…
                        </td>
                    </tr>
                    <tr x-show="!loading && error && activeTab === 'pick-and-pack'">
                        <td :colspan="activeTab === 'shipped' ? 7 : 10" class="px-4 py-6 text-center text-amber-700 text-xs" x-text="error"></td>
                    </tr>
                </tbody>
                <template x-for="order in pickOrdersToShow" :key="'pick-'+order.id">
                    @include('partials.order-row-pick')
                </template>
                <tbody>
                    <tr x-show="activeTab === 'pick-and-pack' && !loading && !error && filteredAndSortedOrders.length === 0">
                        <td colspan="10" class="px-4 py-6 text-center text-slate-500 text-xs">No orders in the last 2 months.</td>
                    </tr>
                </tbody>
                --}}
                <!-- 2. Ready to pack tab -->

                <tbody>
                    <tr x-show="activeTab === 'ready-to-pack' && readyToPackLoading">
                        <td colspan="11" class="px-4 py-6 text-center text-slate-500 text-xs">
                            <span class="inline-block w-5 h-5 border-2 border-slate-400 border-t-transparent rounded-full animate-spin align-middle mr-2"></span>
                            Loading ready to pack orders…
                        </td>
                    </tr>
                    <tr x-show="activeTab === 'ready-to-pack' && !readyToPackLoading && readyToPackError">
                        <td colspan="11" class="px-4 py-6 text-center text-amber-700 text-xs" x-text="readyToPackError"></td>
                    </tr>
                </tbody>
                <template x-for="order in readyToPackOrdersToShow" :key="'rtp-'+order.id">
                    @include('partials.order-row-pick')
                </template>
                <tbody>
                    <tr x-show="activeTab === 'ready-to-pack' && !readyToPackLoading && !readyToPackError && readyToPackFilteredAndSorted.length === 0">
                        <td colspan="11" class="px-4 py-6 text-center text-slate-500 text-xs">No paid orders to pack.</td>
                    </tr>
                    <tr x-show="activeTab === 'ready-to-pack' && !readyToPackLoading && !readyToPackError && readyToPackFilteredAndSorted.length > 0 && readyToPackOrdersToShow.length === 0">
                        <td colspan="11" class="px-4 py-6 text-center text-slate-500 text-xs">Paid orders not available yet.</td>
                    </tr>
                </tbody>
                <tbody>
                    <tr x-show="activeTab === 'ready-for-pickup' && readyForPickupLoading">
                        <td colspan="11" class="px-4 py-6 text-center text-slate-500 text-xs">
                            <span class="inline-block w-5 h-5 border-2 border-slate-400 border-t-transparent rounded-full animate-spin align-middle mr-2"></span>
                            Loading ready for pickup orders…
                        </td>
                    </tr>
                    <tr x-show="activeTab === 'ready-for-pickup' && !readyForPickupLoading && readyForPickupError">
                        <td colspan="11" class="px-4 py-6 text-center text-amber-700 text-xs" x-text="readyForPickupError"></td>
                    </tr>
                </tbody>
                <template x-for="order in readyForPickupOrdersToShow" :key="'rfp-'+order.id">
                    @include('partials.order-row-pick')
                </template>
                <tbody>
                    <tr x-show="activeTab === 'ready-for-pickup' && !readyForPickupLoading && !readyForPickupError && readyForPickupFilteredAndSorted.length === 0">
                        <td colspan="11" class="px-4 py-6 text-center text-slate-500 text-xs">No ready for pickup orders in the last 2 months.</td>
                    </tr>
                </tbody>
                <!-- 4. On hold tab -->
                <tbody>
                    <tr x-show="activeTab === 'on-hold' && onHoldLoading">
                        <td colspan="11" class="px-4 py-6 text-center text-slate-500 text-xs">
                            <span class="inline-block w-5 h-5 border-2 border-slate-400 border-t-transparent rounded-full animate-spin align-middle mr-2"></span>
                            Loading on hold orders…
                        </td>
                    </tr>
                    <tr x-show="activeTab === 'on-hold' && !onHoldLoading && onHoldError">
                        <td colspan="11" class="px-4 py-6 text-center text-amber-700 text-xs" x-text="onHoldError"></td>
                    </tr>
                </tbody>
                <template x-for="order in onHoldOrdersToShow" :key="'onhold-'+order.id">
                    @include('partials.order-row-pick')
                </template>
                <tbody>
                    <tr x-show="activeTab === 'on-hold' && !onHoldLoading && !onHoldError && onHoldFilteredAndSorted.length === 0">
                        <td colspan="11" class="px-4 py-6 text-center text-slate-500 text-xs">No on hold orders or non-available orders in the last 2 months.</td>
                    </tr>
                </tbody>

                <!-- 5. Upcoming tab -->
                <tbody>
                    <tr x-show="activeTab === 'upcoming' && upcomingLoading">
                        <td colspan="11" class="px-4 py-6 text-center text-slate-500 text-xs">
                            <span class="inline-block w-5 h-5 border-2 border-slate-400 border-t-transparent rounded-full animate-spin align-middle mr-2"></span>
                            Loading upcoming orders…
                        </td>
                    </tr>
                    <tr x-show="activeTab === 'upcoming' && !upcomingLoading && upcomingError">
                        <td colspan="11" class="px-4 py-6 text-center text-amber-700 text-xs" x-text="upcomingError"></td>
                    </tr>
                </tbody>
                <template x-for="order in upcomingOrdersToShow" :key="'upcoming-'+order.id">
                    @include('partials.order-row-pick')
                </template>
                <tbody>
                    <tr x-show="activeTab === 'upcoming' && !upcomingLoading && !upcomingError && upcomingFilteredAndSorted.length === 0">
                        <td colspan="11" class="px-4 py-6 text-center text-slate-500 text-xs">No upcoming orders in the last 2 months.</td>
                    </tr>
                </tbody>

                <!-- 6. Archived tab -->
                <tbody>
                    <tr x-show="activeTab === 'shipped' && shippedLoading">
                        <td colspan="8" class="px-4 py-6 text-center text-slate-500 text-xs">
                            <span class="inline-block w-5 h-5 border-2 border-slate-400 border-t-transparent rounded-full animate-spin align-middle mr-2"></span>
                            Loading archived orders…
                        </td>
                    </tr>
                    <tr x-show="activeTab === 'shipped' && !shippedLoading && shippedError">
                        <td colspan="8" class="px-4 py-6 text-center text-amber-700 text-xs" x-text="shippedError"></td>
                    </tr>
                </tbody>
                <template x-for="order in shippedOrdersToShow" :key="'shipped-'+order.id">
                    @include('partials.order-row-shipped')
                </template>
                <tbody>
                    <tr x-show="activeTab === 'shipped' && !shippedLoading && !shippedError && sortedShippedOrders.length === 0">
                        <td colspan="8" class="px-4 py-6 text-center text-slate-500 text-xs">No archived orders in the last 2 months.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <p
        x-cloak
        x-show="ordersPageFooterNote"
        class="mt-6 text-center text-xs text-slate-500"
        x-text="ordersPageFooterNote"
    ></p>

    @include('partials.modal-print-label')
    @include('partials.modal-gia')
</main>

<script>
    function ordersPage() {
        const twoMonthsAgo = (() => {
            const d = new Date();
            d.setMonth(d.getMonth() - 2);
            return d.getTime();
        })();
        return {
            orders: [],
            shippedOrders: [],
            readyToPackOrders: [],
            readyToPackLoading: false,
            readyToPackError: null,
            readyForPickupOrders: [],
            readyForPickupLoading: false,
            readyForPickupError: null,
            onHoldOrders: [],
            onHoldLoading: false,
            onHoldError: null,
            upcomingOrders: [],
            upcomingLoading: false,
            upcomingError: null,
            shopDomain: null,
            webshipperAccount: null,
            integrationStatus: null,
            appStatus: 'test',
            _initStarted: false,
            loading: true,
            shippedLoading: false,
            error: null,
            shippedError: null,
            activeTab: 'ready-to-pack',
            tabBeforeOnHold: 'upcoming',
            expandedIds: {},
            shippedExpandedIds: {},
            labelLoadingWsOrderId: null,
            printLabelConfirmWsOrderId: null,
            returnLabelLoadingWsOrderId: null,
            giaInputByOrderId: {},
            giaConfirm: null,
            giaLoadingOrderId: null,

            logButtonClick(buttonName, context = {}) {
                const body = {
                    button: buttonName,
                    ...context
                };
                const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                fetch('/api/log-button-click', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(body)
                }).catch(() => {});
            },

            /* Pick & Pack tab: deactivated — uncomment with template block + tab button
            get filteredAndSortedOrders() {
                const filtered = this.orders.filter(o => new Date(o.created_at).getTime() >= twoMonthsAgo);
                const allAvailable = (o) => {
                    if (!o.line_items || o.line_items.length === 0) return false;
                    return o.line_items.every(item => {
                        if (item.custom_item) return true;
                        // Old: const avail = (item.inventory_quantity ?? 0) + (item.committed_quantity ?? 0);
                        const avail = item.inventory_quantity ?? 0;
                        return item.quantity <= avail;
                    });
                };
                const getBCShipDate = (bc) => {
                    const raw = bc.shipment_date ?? bc.requested_delivery_date ?? null;
                    if (!raw || !raw.trim()) return null;
                    const date = new Date(raw);
                    if (isNaN(date.getTime()) || date.getFullYear() < 1900) return null;
                    return raw;
                };
                return [...filtered].sort((a, b) => {
                    const aReady = allAvailable(a);
                    const bReady = allAvailable(b);
                    if (aReady !== bReady) return aReady ? -1 : 1;
                    const shipDateA = a.business_central && getBCShipDate(a.business_central);
                    const shipDateB = b.business_central && getBCShipDate(b.business_central);
                    const sortKeyA = shipDateA ?? '9999-12-31';
                    const sortKeyB = shipDateB ?? '9999-12-31';
                    return sortKeyA.localeCompare(sortKeyB);
                });
            },
            */

            get readyToPackFilteredAndSorted() {
                const pickupInProgress = (o) =>
                    o.delivery_method === 'Pickup' &&
                    (o.fulfillment_orders || []).some((e) => e?.node?.status === 'IN_PROGRESS');
                const filtered = this.readyToPackOrders.filter(
                    (o) =>
                    new Date(o.created_at).getTime() >= twoMonthsAgo &&
                    !pickupInProgress(o)
                );
                const allAvailable = (o) => {
                    if (!o.line_items || o.line_items.length === 0) return false;
                    return o.line_items.every(item => {
                        if (item.custom_item) return true;
                        // Old: const avail = (item.inventory_quantity ?? 0) + (item.committed_quantity ?? 0);
                        const avail = item.inventory_quantity ?? 0;
                        return item.quantity <= avail;
                    });
                };
                const getBCShipDate = (bc) => {
                    const raw = bc.shipment_date ?? bc.requested_delivery_date ?? null;
                    if (!raw || !raw.trim()) return null;
                    const date = new Date(raw);
                    if (isNaN(date.getTime()) || date.getFullYear() < 1900) return null;
                    return raw;
                };
                return [...filtered].sort((a, b) => {
                    const aReady = allAvailable(a);
                    const bReady = allAvailable(b);
                    if (aReady !== bReady) return aReady ? -1 : 1;
                    const shipDateA = a.business_central && getBCShipDate(a.business_central);
                    const shipDateB = b.business_central && getBCShipDate(b.business_central);
                    const sortKeyA = shipDateA ?? '9999-12-31';
                    const sortKeyB = shipDateB ?? '9999-12-31';
                    return sortKeyA.localeCompare(sortKeyB);
                });
            },

            get readyForPickupFilteredAndSorted() {
                const twoMonthsAgo = new Date();
                twoMonthsAgo.setMonth(twoMonthsAgo.getMonth() - 2);
                const filtered = this.readyForPickupOrders.filter(order => {
                    const created = new Date(order.created_at);
                    return !isNaN(created.getTime()) && created >= twoMonthsAgo;
                });
                const allAvailable = (o) => {
                    if (!o.line_items || o.line_items.length === 0) return false;
                    return o.line_items.every(item => {
                        if (item.custom_item) return true;
                        // Old: const avail = (item.inventory_quantity ?? 0) + (item.committed_quantity ?? 0);
                        const avail = item.inventory_quantity ?? 0;
                        return item.quantity <= avail;
                    });
                };
                const getBCShipDate = (bc) => {
                    const raw = bc.shipment_date ?? bc.requested_delivery_date ?? null;
                    if (!raw || !raw.trim()) return null;
                    const date = new Date(raw);
                    if (isNaN(date.getTime()) || date.getFullYear() < 1900) return null;
                    return raw;
                };
                return [...filtered].sort((a, b) => {
                    const aReady = allAvailable(a);
                    const bReady = allAvailable(b);
                    if (aReady !== bReady) return aReady ? -1 : 1;
                    const shipDateA = a.business_central && getBCShipDate(a.business_central);
                    const shipDateB = b.business_central && getBCShipDate(b.business_central);
                    const sortKeyA = shipDateA ?? '9999-12-31';
                    const sortKeyB = shipDateB ?? '9999-12-31';
                    return sortKeyA.localeCompare(sortKeyB);
                });
            },

            get onHoldFilteredAndSorted() {
                const twoMonthsAgo = new Date();
                twoMonthsAgo.setMonth(twoMonthsAgo.getMonth() - 2);
                const filtered = this.onHoldOrders.filter(order => {
                    const created = new Date(order.created_at);
                    return !isNaN(created.getTime()) && created >= twoMonthsAgo;
                });
                const allAvailable = (o) => {
                    if (!o.line_items || o.line_items.length === 0) return false;
                    return o.line_items.every(item => {
                        if (item.custom_item) return true;
                        // Old: const avail = (item.inventory_quantity ?? 0) + (item.committed_quantity ?? 0);
                        const avail = item.inventory_quantity ?? 0;
                        return item.quantity <= avail;
                    });
                };
                const getBCShipDate = (bc) => {
                    const raw = bc.shipment_date ?? bc.requested_delivery_date ?? null;
                    if (!raw || !raw.trim()) return null;
                    const date = new Date(raw);
                    if (isNaN(date.getTime()) || date.getFullYear() < 1900) return null;
                    return raw;
                };
                return [...filtered].sort((a, b) => {
                    const aReady = allAvailable(a);
                    const bReady = allAvailable(b);
                    if (aReady !== bReady) return aReady ? -1 : 1;
                    const shipDateA = a.business_central && getBCShipDate(a.business_central);
                    const shipDateB = b.business_central && getBCShipDate(b.business_central);
                    const sortKeyA = shipDateA ?? '9999-12-31';
                    const sortKeyB = shipDateB ?? '9999-12-31';
                    return sortKeyA.localeCompare(sortKeyB);
                });
            },

            get upcomingFilteredAndSorted() {
                const twoMonthsAgo = new Date();
                twoMonthsAgo.setMonth(twoMonthsAgo.getMonth() - 2);
                const filtered = this.upcomingOrders.filter(order => {
                    const created = new Date(order.created_at);
                    return !isNaN(created.getTime()) && created >= twoMonthsAgo;
                });
                const allAvailable = (o) => {
                    if (!o.line_items || o.line_items.length === 0) return false;
                    return o.line_items.every(item => {
                        if (item.custom_item) return true;
                        // Old: const avail = (item.inventory_quantity ?? 0) + (item.committed_quantity ?? 0);
                        const avail = item.inventory_quantity ?? 0;
                        return item.quantity <= avail;
                    });
                };
                const getBCShipDate = (bc) => {
                    const raw = bc.shipment_date ?? bc.requested_delivery_date ?? null;
                    if (!raw || !raw.trim()) return null;
                    const date = new Date(raw);
                    if (isNaN(date.getTime()) || date.getFullYear() < 1900) return null;
                    return raw;
                };
                return [...filtered].sort((a, b) => {
                    const aReady = allAvailable(a);
                    const bReady = allAvailable(b);
                    if (aReady !== bReady) return aReady ? -1 : 1;
                    const shipDateA = a.business_central && getBCShipDate(a.business_central);
                    const shipDateB = b.business_central && getBCShipDate(b.business_central);
                    const sortKeyA = shipDateA ?? '9999-12-31';
                    const sortKeyB = shipDateB ?? '9999-12-31';
                    return sortKeyA.localeCompare(sortKeyB);
                });
            },

            get sortedShippedOrders() {
                return [...this.shippedOrders].sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime());
            },

            /* Pick & Pack tab: deactivated
            get pickOrdersToShow() {
                if (this.activeTab !== 'pick-and-pack' || this.loading || this.error) return [];
                return this.filteredAndSortedOrders;
            },
            */
            get shippedOrdersToShow() {
                if (this.activeTab !== 'shipped' || this.shippedLoading || this.shippedError) return [];
                return this.sortedShippedOrders;
            },
            get readyToPackOrdersToShow() {
                if (this.activeTab !== 'ready-to-pack' || this.readyToPackLoading || this.readyToPackError) return [];
                return this.readyToPackFilteredAndSorted.filter((o) => this.isAllProductsAvailable(o));
            },
            get readyForPickupOrdersToShow() {
                if (this.activeTab !== 'ready-for-pickup' || this.readyForPickupLoading || this.readyForPickupError) return [];
                return this.readyForPickupFilteredAndSorted;
            },
            get onHoldOrdersToShow() {
                if (this.activeTab !== 'on-hold' || this.onHoldLoading || this.onHoldError) return [];
                return this.onHoldFilteredAndSorted;
            },
            get upcomingOrdersToShow() {
                if (this.activeTab !== 'upcoming' || this.upcomingLoading || this.upcomingError) return [];
                return this.upcomingFilteredAndSorted;
            },

            get mutationsEnabled() {
                return this.appStatus === 'production';
            },

            get integrationStatusNotice() {
                const sources = this.integrationStatus?.sources || {};
                const businessCentralStatus = sources.business_central?.status || null;
                const webshipperStatus = sources.webshipper?.status || null;

                if (businessCentralStatus === 'disabled' && webshipperStatus === 'disabled') {
                    return 'BC and Webshipper data are shown as test data and placeholders.';
                }

                if (businessCentralStatus === 'not_configured' && webshipperStatus === 'not_configured') {
                    return 'BC and Webshipper are not configured. Shopify orders are still shown.';
                }

                if (businessCentralStatus === 'failed' && webshipperStatus === 'failed') {
                    return 'BC and Webshipper data could not be loaded. Shopify orders are still shown.';
                }

                const messages = Object.entries(sources)
                    .filter((source) =>
                        source[1] &&
                        source[1].message &&
                        source[1].status !== 'loaded' &&
                        source[1].status !== 'pending'
                    )
                    .map((source) => source[1].message);

                return [...new Set(messages)].join(' ');
            },

            get ordersPageFooterNote() {
                return this.integrationStatusNotice ?
                    'Integration status: ' + this.integrationStatusNotice :
                    'Shopify orders come from the connected development store.';
            },

            toggleExpanded(id) {
                const key = String(id);
                const now = Date.now();
                if (this._lastExpandKey === key && now - (this._lastExpandTime || 0) < 200) return;
                this._lastExpandKey = key;
                this._lastExpandTime = now;
                this.expandedIds[key] = !this.expandedIds[key];
            },
            toggleShippedExpanded(id) {
                const key = String(id);
                const now = Date.now();
                if (this._lastShippedExpandKey === key && now - (this._lastShippedExpandTime || 0) < 200) return;
                this._lastShippedExpandKey = key;
                this._lastShippedExpandTime = now;
                this.shippedExpandedIds[key] = !this.shippedExpandedIds[key];
            },
            isExpanded(id) {
                return !!this.expandedIds[String(id)];
            },
            isShippedExpanded(id) {
                return !!this.shippedExpandedIds[String(id)];
            },

            formatDate(s) {
                try {
                    const d = new Date(s);
                    if (isNaN(d.getTime())) return s;
                    return d.toLocaleString(undefined, {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                } catch {
                    return s;
                }
            },
            formatDateOnly(s) {
                try {
                    const d = new Date(s);
                    if (isNaN(d.getTime())) return s;
                    return d.toLocaleDateString(undefined, {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric'
                    });
                } catch {
                    return s;
                }
            },
            getBCShipDate(bc) {
                const raw = bc.shipment_date ?? bc.requested_delivery_date ?? null;
                if (!raw || !raw.trim()) return null;
                const d = new Date(raw);
                if (isNaN(d.getTime()) || d.getFullYear() < 1900) return null;
                return raw;
            },
            formatMoney(amount, currency) {
                return new Intl.NumberFormat(undefined, {
                    style: 'currency',
                    currency: currency || 'USD'
                }).format(parseFloat(amount || '0'));
            },
            formatAddress(a) {
                if (!a) return '—';
                const lines = [
                    [a.first_name, a.last_name].filter(Boolean).join(' '),
                    a.address1,
                    a.address2,
                    [a.zip, a.city].filter(Boolean).join(' '),
                    [a.province, a.country].filter(Boolean).join(', ')
                ].filter(Boolean);
                return lines.join('\n') || '—';
            },
            /*
            Old variant display lived inline in the row partials:
            (item.variant_options && item.variant_options.length > 0)
                ? item.variant_options.map(o => o.name + ': ' + o.value).join(', ')
                : '—'
            */
            variantLabel(item) {
                const options = item.variant_options || [];
                if (options.length > 0) {
                    return options.map((option) => option.name + ': ' + option.value).join(', ');
                }
                if (item.variant_title && item.variant_title !== 'Default Title') {
                    return item.variant_title;
                }
                if (item.sku) {
                    return item.sku;
                }
                return '-';
            },
            availableQuantity(item) {
                if (item.custom_item) return 0;
                // Old: return (item.inventory_quantity ?? 0) + (item.committed_quantity ?? 0);
                return item.inventory_quantity ?? 0;
            },
            isAllProductsAvailable(order) {
                if (!order.line_items || order.line_items.length === 0) return false;
                return order.line_items.every(item => item.quantity <= this.availableQuantity(item));
            },
            getMissingLineItems(order) {
                return (order.line_items || []).filter(item => item.quantity > this.availableQuantity(item));
            },
            availabilityPercentage(order) {
                if (!order.line_items || order.line_items.length === 0) return null;
                const availableCount = order.line_items.filter(item => item.quantity <= this.availableQuantity(item)).length;
                return Math.round((availableCount / order.line_items.length) * 100);
            },

            async parseJsonResponse(res) {
                const text = await res.text();
                const ct = res.headers.get('content-type') || '';
                if (!ct.includes('application/json')) {
                    throw new Error(text && text.length < 200 ? text : 'Server returned an error (not JSON). Check Laravel logs.');
                }
                try {
                    return text ? JSON.parse(text) : {};
                } catch (e) {
                    throw new Error('Invalid JSON from server. Check Laravel logs.');
                }
            },

            applyOrderResponseMetadata(data) {
                if (data.shop_domain) this.shopDomain = data.shop_domain;
                if (data.webshipper_account !== undefined) {
                    this.webshipperAccount = data.webshipper_account;
                }
                if (data.VITE_APP_STATUS !== undefined) {
                    this.appStatus = data.VITE_APP_STATUS === 'production' ? 'production' : 'test';
                }
                if (data.integration_status !== undefined) {
                    this.integrationStatus = data.integration_status;
                }
            },

            clearIntegrationStatus() {
                this.integrationStatus = null;
            },

            async init() {
                if (this._initStarted) {
                    return;
                }
                this._initStarted = true;
                try {
                    // Pick & Pack tab is disabled; `this.orders` is unused. Default tab is ready-to-pack which loads via loadReadyToPackIfNeeded — skip redundant full open-orders fetch to avoid doubling slow API work (and serializing two heavy requests on a single dev worker).
                    const skipRedundantBaseOrdersFetch = this.activeTab === 'ready-to-pack';
                    if (!skipRedundantBaseOrdersFetch) {
                        const res = await fetch('/api/shopify/orders', {
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        const data = await this.parseJsonResponse(res);
                        if (!res.ok) throw new Error(data.error || 'Failed to load orders');
                        this.orders = data.orders ?? [];
                        this.applyOrderResponseMetadata(data);
                        this.error = null;
                    } else {
                        this.orders = [];
                        this.error = null;
                    }
                } catch (err) {
                    this.error = err?.message ?? 'Failed to load orders';
                    this.clearIntegrationStatus();
                    this.orders = [];
                } finally {
                    this.loading = false;
                }
                await this.loadReadyToPackIfNeeded();
            },

            async loadShippedIfNeeded() {
                if (this.shippedOrders.length > 0 || this.shippedLoading) return;
                this.shippedLoading = true;
                this.shippedError = null;
                try {
                    const res = await fetch('/api/shopify/orders?archived=true', {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const data = await this.parseJsonResponse(res);
                    if (!res.ok) throw new Error(data.error || 'Failed to load archived orders');
                    this.shippedOrders = data.orders ?? [];
                    this.applyOrderResponseMetadata(data);
                } catch (err) {
                    this.shippedError = err?.message ?? 'Failed to load archived orders';
                    this.clearIntegrationStatus();
                    this.shippedOrders = [];
                } finally {
                    this.shippedLoading = false;
                }
            },

            async loadReadyToPackIfNeeded() {
                if (this.readyToPackOrders.length > 0 || this.readyToPackLoading) return;
                this.readyToPackLoading = true;
                this.readyToPackError = null;
                try {
                    const res = await fetch('/api/shopify/orders?view=ready-to-pack', {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const data = await this.parseJsonResponse(res);
                    if (!res.ok) throw new Error(data.error || 'Failed to load ready-to-pack orders');
                    this.readyToPackOrders = data.orders ?? [];
                    this.applyOrderResponseMetadata(data);
                } catch (err) {
                    this.readyToPackError = err?.message ?? 'Failed to load ready-to-pack orders';
                    this.clearIntegrationStatus();
                    this.readyToPackOrders = [];
                } finally {
                    this.readyToPackLoading = false;
                }
            },

            async loadReadyForPickupIfNeeded() {
                if (this.readyForPickupOrders.length > 0 || this.readyForPickupLoading) return;
                this.readyForPickupLoading = true;
                this.readyForPickupError = null;
                try {
                    const res = await fetch('/api/shopify/orders?view=ready-for-pickup', {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const data = await this.parseJsonResponse(res);
                    if (!res.ok) throw new Error(data.error || 'Failed to load ready for pickup orders');
                    this.readyForPickupOrders = data.orders ?? [];
                    this.applyOrderResponseMetadata(data);
                } catch (err) {
                    this.readyForPickupError = err?.message ?? 'Failed to load ready for pickup orders';
                    this.clearIntegrationStatus();
                    this.readyForPickupOrders = [];
                } finally {
                    this.readyForPickupLoading = false;
                }
            },

            async loadOnHoldIfNeeded() {
                if (this.onHoldOrders.length > 0 || this.onHoldLoading) return;
                this.onHoldLoading = true;
                this.onHoldError = null;
                try {
                    const res = await fetch('/api/shopify/orders?view=on-hold', {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const data = await this.parseJsonResponse(res);
                    if (!res.ok) throw new Error(data.error || 'Failed to load on hold orders');
                    this.onHoldOrders = data.orders ?? [];
                    this.applyOrderResponseMetadata(data);
                } catch (err) {
                    this.onHoldError = err?.message ?? 'Failed to load on hold orders';
                    this.clearIntegrationStatus();
                    this.onHoldOrders = [];
                } finally {
                    this.onHoldLoading = false;
                }
            },


            async addOnHoldTag(order) {
                if (!order?.id) {
                    return;
                }
                this.logButtonClick('add-order-on-hold', {
                    order_id: order.id
                });

                const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                if (!token) {
                    alert('Session expired. Please refresh and try again.');
                    return;
                }

                const url = '/api/shopify/orders/' + encodeURIComponent(order.id) + '/on-hold';

                try {
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': token,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({}),
                    });

                    const data = await this.parseJsonResponse(res);
                    if (!res.ok || !data.ok) {
                        throw new Error(data.error || data.detail || 'Failed to add On hold in Shopify');
                    }

                    // Keep UI responsive immediately after successful backend write
                    let tags = [];
                    if (Array.isArray(order.tags)) tags = [...order.tags];
                    else if (typeof order.tags === 'string' && order.tags.trim() !== '') tags = order.tags.split(',').map(t => t.trim()).filter(Boolean);
                    if (!tags.includes('On hold')) tags.push('On hold');
                    order.tags = tags;

                    if (this.activeTab === 'ready-to-pack' || this.activeTab === 'ready-for-pickup' || this.activeTab === 'upcoming') {
                        this.tabBeforeOnHold = this.activeTab;
                    } else {
                        this.tabBeforeOnHold = 'upcoming';
                    }

                    this.upcomingOrders = this.upcomingOrders.filter(o => o.id !== order.id);
                    this.readyToPackOrders = this.readyToPackOrders.filter(o => o.id !== order.id);
                    this.readyForPickupOrders = this.readyForPickupOrders.filter(o => o.id !== order.id);
                    this.onHoldOrders = [order, ...this.onHoldOrders.filter(o => o.id !== order.id)];
                    this.activeTab = 'on-hold';
                    alert('On hold saved and added in Shopify.');
                } catch (e) {
                    console.error('[OnHold API] add failed', e);
                    alert('Could not update On hold. Please try again.');
                }
            },

            async readyOrderForPickup(fulfillmentOrderId) {
                if (!fulfillmentOrderId || typeof fulfillmentOrderId !== 'string') {
                    alert('Missing fulfillment order.');
                    return;
                }

                const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                if (!token) {
                    alert('Session expired. Please refresh and try again.');
                    return;
                }

                try {
                    const res = await fetch('/api/shopify/orders/ready-for-pickup', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': token,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({
                            fulfillment_order_id: fulfillmentOrderId,
                        }),
                    });

                    const data = await this.parseJsonResponse(res);
                    if (!res.ok || !data.ok) {
                        throw new Error(data.error || data.detail || 'Failed to ready order for pickup in Shopify');
                    }

                    alert('Order ready for pickup in Shopify.');
                } catch (e) {
                    console.error('[ReadyOrderForPickup API] failed', e);
                    alert(e?.message ?? 'Could not ready order for pickup. Please try again.');
                }
            },

            async markOrderAsPickedUp(fulfillmentOrderId) {
                if (!fulfillmentOrderId || typeof fulfillmentOrderId !== 'string') {
                    alert('Missing fulfillment order.');
                    return;
                }
                const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                if (!token) {
                    alert('Session expired. Please refresh and try again.');
                    return;
                }

                try {
                    const res = await fetch('/api/shopify/orders/mark-as-picked-up', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': token,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({
                            fulfillment_order_id: fulfillmentOrderId,
                        }),
                    });

                    const data = await this.parseJsonResponse(res);
                    if (!res.ok || !data.ok) {
                        throw new Error(data.error || data.detail || 'Failed to mark order as picked up in Shopify');
                    }

                    alert('Order marked as picked up in Shopify.');
                } catch (e) {
                    console.error('[MarkOrderAsPickedUp API] failed', e);
                    alert(e?.message ?? 'Could not mark order as picked up. Please try again.');
                }
            },

            async loadUpcomingIfNeeded() {
                if (this.upcomingOrders.length > 0 || this.upcomingLoading) return;
                this.upcomingLoading = true;
                this.upcomingError = null;
                try {
                    const res = await fetch('/api/shopify/orders?view=upcoming', {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const data = await this.parseJsonResponse(res);
                    if (!res.ok) throw new Error(data.error || 'Failed to load upcoming orders');
                    this.upcomingOrders = data.orders ?? [];
                    this.applyOrderResponseMetadata(data);
                } catch (err) {
                    this.upcomingError = err?.message ?? 'Failed to load upcoming orders';
                    this.clearIntegrationStatus();
                    this.upcomingOrders = [];
                } finally {
                    this.upcomingLoading = false;
                }
            },
            async removeOnHoldTag(order) {
                if (!order?.id) {
                    return;
                }
                this.logButtonClick('remove-order-on-hold', {
                    order_id: order.id
                });

                const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                if (!token) {
                    alert('Session expired. Please refresh and try again.');
                    return;
                }

                const url = '/api/shopify/orders/' + encodeURIComponent(order.id) + '/on-hold';

                try {
                    const res = await fetch(url, {
                        method: 'DELETE',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': token,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    const data = await this.parseJsonResponse(res);
                    if (!res.ok || !data.ok) {
                        throw new Error(data.error || data.detail || 'Failed to remove On hold in Shopify');
                    }

                    let tags = [];
                    if (Array.isArray(order.tags)) tags = [...order.tags];
                    else if (typeof order.tags === 'string' && order.tags.trim() !== '') tags = order.tags.split(',').map(t => t.trim()).filter(Boolean);
                    tags = tags.filter(t => t !== 'On hold');
                    order.tags = tags;

                    this.onHoldOrders = this.onHoldOrders.filter(o => o.id !== order.id);

                    const oid = order.id;
                    const drop = (list) => list.filter((o) => o.id !== oid);
                    this.upcomingOrders = drop(this.upcomingOrders);
                    this.readyToPackOrders = drop(this.readyToPackOrders);
                    this.readyForPickupOrders = drop(this.readyForPickupOrders);

                    let nextTab = 'upcoming';
                    if (this.tabBeforeOnHold === 'ready-to-pack') {
                        nextTab = 'ready-to-pack';
                    } else if (this.tabBeforeOnHold === 'ready-for-pickup') {
                        nextTab = 'ready-for-pickup';
                    }
                    this.activeTab = nextTab;

                    if (nextTab === 'ready-to-pack') {
                        this.readyToPackOrders = [order, ...this.readyToPackOrders];
                    } else if (nextTab === 'ready-for-pickup') {
                        this.readyForPickupOrders = [order, ...this.readyForPickupOrders];
                    } else {
                        this.upcomingOrders = [order, ...this.upcomingOrders];
                    }

                    alert('On hold removed in Shopify.');
                } catch (e) {
                    console.error('[OnHold API] remove failed', e);
                    alert('Could not update On hold. Please try again.');
                }
            },

            openPrintLabelConfirm(wsOrderId) {
                this.printLabelConfirmWsOrderId = wsOrderId;
            },
            /**
             * Open a tab synchronously on user click, await the PDF, then assign the blob URL to that tab.
             * window.open after await fetch is treated as a popup and blocked in embedded Shopify admin.
             */
            assignPdfBase64ToWindow(win, pdfBase64) {
                const binary = atob(pdfBase64);
                const bytes = new Uint8Array(binary.length);
                for (let i = 0; i < binary.length; i++) {
                    bytes[i] = binary.charCodeAt(i);
                }
                const blob = new Blob([bytes], {
                    type: 'application/pdf'
                });
                const url = URL.createObjectURL(blob);
                win.location.href = url;
                setTimeout(() => URL.revokeObjectURL(url), 120000);
            },
            async handlePrintLabel() {
                const id = this.printLabelConfirmWsOrderId;
                this.printLabelConfirmWsOrderId = null;
                if (id == null) return;
                const pdfWindow = window.open('about:blank', '_blank');
                if (!pdfWindow) {
                    alert('Popup blocked. Allow popups for this embedded app (browser site settings for your app URL), then try again.');
                    return;
                }
                this.labelLoadingWsOrderId = id;
                try {
                    const res = await fetch('/api/webshipper/label?orderId=' + encodeURIComponent(id), {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    const data = await this.parseJsonResponse(res);
                    if (!data.ok || typeof data.pdfBase64 !== 'string') {
                        pdfWindow.close();
                        alert(data.error ?? 'Could not load label');
                        return;
                    }
                    this.assignPdfBase64ToWindow(pdfWindow, data.pdfBase64);
                } catch (e) {
                    pdfWindow.close();
                    alert(e?.message ?? 'Failed to load label');
                } finally {
                    this.labelLoadingWsOrderId = null;
                }
            },

            async handleCreateReturnLabel(wsOrderId) {
                const pdfWindow = window.open('about:blank', '_blank');
                if (!pdfWindow) {
                    alert('Popup blocked. Allow popups for this embedded app (browser site settings for your app URL), then try again.');
                    return;
                }
                this.returnLabelLoadingWsOrderId = wsOrderId;
                try {
                    const res = await fetch('/api/webshipper/return-label?orderId=' + encodeURIComponent(wsOrderId), {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    const data = await this.parseJsonResponse(res);
                    if (!data.ok || typeof data.pdfBase64 !== 'string') {
                        pdfWindow.close();
                        alert(data.error ?? 'Could not create return label');
                        return;
                    }
                    this.assignPdfBase64ToWindow(pdfWindow, data.pdfBase64);
                } catch (e) {
                    pdfWindow.close();
                    alert(e?.message ?? 'Failed to create return label');
                } finally {
                    this.returnLabelLoadingWsOrderId = null;
                }
            },

            // POST ORDER TO BC BUTTON
            // TO DO: add the correct logic to the post order to BC button
            // for now only logging is added 

            handlePostOrderInBc(order) {
                // Placeholder: this will run once button is enabled in production.
                console.log('TODO post-order-in-bc click', {
                    orderId: order?.id ?? null,
                    bcOrderId: order?.business_central?.order_id ?? null,
                });

                // Prepared click log (will run when button is enabled)
                this.logButtonClick('post-order-in-bc-pick', {
                    order_id: order?.id ?? null,
                    bc_order_id: order?.business_central?.order_id ?? null,
                });

                // TODO: call BC post-order endpoint here later,
                // then backend should log success/failure events.
            },

            openGiaConfirm(order) {
                const bc = order.business_central;
                const gia = (this.giaInputByOrderId[order.id] ?? '').trim();
                if (!gia) {
                    alert('Please enter a GIA number.');
                    return;
                }
                if (!bc) return;
                this.giaConfirm = {
                    orderId: order.id,
                    orderName: order.name,
                    bcOrderId: bc.order_id,
                    bcOrderNumber: bc.number,
                    giaNumber: gia
                };
            },
            async handleAddGiaConfirm() {
                if (!this.giaConfirm) return;
                const {
                    orderId,
                    bcOrderId,
                    giaNumber
                } = this.giaConfirm;
                this.giaConfirm = null;
                this.giaLoadingOrderId = orderId;
                try {
                    const res = await fetch('/api/business-central/orders/' + encodeURIComponent(bcOrderId) + '/lines', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                        },
                        body: JSON.stringify({
                            description: giaNumber
                        })
                    });
                    const data = await this.parseJsonResponse(res);
                    if (data.ok) {
                        const next = {
                            ...this.giaInputByOrderId
                        };
                        delete next[orderId];
                        this.giaInputByOrderId = next;
                    } else {
                        alert(data.error ?? 'Failed to add GIA to Business Central');
                    }
                } catch (e) {
                    alert(e?.message ?? 'Failed to add GIA to Business Central');
                } finally {
                    this.giaLoadingOrderId = null;
                }
            }
        };
    }
</script>
@endsection
