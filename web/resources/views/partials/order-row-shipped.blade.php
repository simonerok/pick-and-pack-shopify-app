<tbody>
<tr class="border-b border-slate-100 hover:bg-slate-50/50">
    <td class="w-10 px-2 py-2 align-middle" data-label="">
        <button type="button" @click.stop="toggleShippedExpanded(order.id)" class="flex items-center justify-center w-5 h-5 rounded border border-black bg-transparent text-black hover:bg-black hover:text-white transition-colors" :aria-expanded="isShippedExpanded(order.id)" :title="isShippedExpanded(order.id) ? 'Collapse' : 'Expand'">
            <svg class="w-3 h-3 shrink-0 transition-transform" :class="isShippedExpanded(order.id) ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path d="M9 18l6-6-6-6"/></svg>
        </button>
    </td>
    <td class="px-4 py-2 align-middle text-xs" data-label="Order">
        <div class="flex flex-col gap-0.5">
            <div class="text-slate-500 text-[11px]" x-text="formatDate(order.created_at)"></div>
            <span class="font-medium text-slate-800" x-text="order.name"></span>
            <template x-if="order.business_central">
                <span class="text-slate-600 font-normal" x-text="'BC: ' + (order.business_central?.number || '')"></span>
            </template>
            <template x-if="order.webshipper">
                <span class="text-slate-600 font-normal" x-text="'WS: ' + (order.webshipper?.status || ('#' + order.webshipper?.order_id)) + (order.webshipper?.tracking_numbers?.length > 0 ? ' (' + order.webshipper.tracking_numbers.length + ' tracking)' : '')"></span>
            </template>
        </div>
    </td>
    {{-- Old archived row skipped the Gift cell here, so every column after Order was shifted left. --}}
    <td class="px-4 py-2 text-xs" data-label="Gift">
        <template x-if="Array.isArray(order.tags) && order.tags.includes('Gift')">
            <span class="text-slate-600 font-normal inline-flex items-center" aria-label="Gift order">
                <x-heroicon-o-gift class="w-4 h-4" />
            </span>
        </template>
        <template x-if="!Array.isArray(order.tags) || !order.tags.includes('Gift')">
            <span class="text-slate-600 font-normal">&mdash;</span>
        </template>
    </td>
    <td class="px-4 py-2 text-xs" data-label="Customer">
        <div class="text-slate-700" x-text="order.billing_address ? (order.billing_address.first_name + ' ' + order.billing_address.last_name) : '—'"></div>
        <div class="text-slate-500 truncate max-w-[180px]" x-show="order.email" x-text="order.email"></div>
    </td>
    <td class="px-4 py-2 text-slate-600 text-xs" data-label="Delivery" x-text="order.delivery_method || '—'"></td>
    <td class="px-4 py-2" data-label="Financial">
        <span class="inline-flex px-2 py-0.5 rounded text-[11px] font-medium" :class="order.financial_status === 'paid' ? 'bg-emerald-100 text-emerald-800' : order.financial_status === 'pending' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600'" x-text="order.financial_status"></span>
    </td>
    <td class="px-4 py-2" data-label="Fulfillment">
        <span class="inline-flex px-2 py-0.5 rounded text-[11px] font-medium" :class="order.fulfillment_status === 'fulfilled' ? 'bg-emerald-100 text-emerald-800' : order.fulfillment_status === 'partial' ? 'bg-blue-100 text-blue-800' : 'bg-slate-100 text-slate-600'" x-text="order.fulfillment_status || '—'"></span>
    </td>
    <td class="px-2 py-2 align-middle w-20 md:w-20 box-border" data-label="Actions">
        <div class="flex justify-start md:justify-end">
            <x-dropdown align="right" width="48" contentClasses="px-2 py-1 bg-white">
                <x-slot name="trigger">
                    <button type="button" class="inline-flex items-center justify-center w-8 h-8 rounded bg-transparent text-black" aria-label="Open order actions">
                        <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <circle cx="4" cy="10" r="1.6"></circle>
                            <circle cx="10" cy="10" r="1.6"></circle>
                            <circle cx="16" cy="10" r="1.6"></circle>
                        </svg>
                    </button>
                </x-slot>

                <x-slot name="content">
                    <a
                        x-show="shopDomain"
                        :href="'https://' + shopDomain + '/admin/orders/' + order.id"
                        target="_blank"
                        rel="noopener noreferrer"
                        @click="$dispatch('close'); logButtonClick('view-in-shopify-order-shipped', { order_id: order.id })"
                        class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out"
                    >
                        View in Shopify
                    </a>

                    <a
                        x-show="order.webshipper && (order.webshipper.shipment_url || order.webshipper.order_url || (webshipperAccount && order.webshipper.order_id))"
                        :href="order.webshipper?.shipment_url || order.webshipper?.order_url || (webshipperAccount ? 'https://' + webshipperAccount + '.webshipper.io/ship/orders/' + order.webshipper?.order_id : '#')"
                        target="_blank"
                        rel="noopener noreferrer"
                        @click="$dispatch('close'); logButtonClick('view-in-webshipper-shipped', { order_id: order.id, ws_order_id: order.webshipper?.order_id })"
                        class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out"
                    >
                        View in Webshipper
                    </a>

                    <a
                        href="#"
                        x-show="order.webshipper"
                        @click.prevent="if (!mutationsEnabled || returnLabelLoadingWsOrderId === order.webshipper?.order_id) return; $dispatch('close'); handleCreateReturnLabel(order.webshipper.order_id)"
                        :class="(!mutationsEnabled || returnLabelLoadingWsOrderId === order.webshipper?.order_id) ? 'block w-full px-4 py-2 text-start text-sm leading-5 text-gray-400 cursor-not-allowed' : 'block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out'"
                    >
                        <span x-text="returnLabelLoadingWsOrderId === order.webshipper?.order_id ? 'Creating…' : 'Create return label'"></span>
                    </a>
                </x-slot>
            </x-dropdown>
        </div>
    </td>
</tr>
<tr class="bg-slate-50/80" x-show="isShippedExpanded(order.id)" x-transition>
    <td colspan="8" class="px-4 py-4">
        <div class="space-y-4 text-xs">
            <div>
                <h4 class="font-medium text-slate-700 mb-1.5 text-xs">Products</h4>
                <template x-if="order.line_items && order.line_items.length > 0">
                    <div class="rounded-lg border border-slate-200 overflow-hidden product-lines-wrap">
                        <table class="w-full text-left text-xs">
                            <thead>
                                <tr class="border-b border-slate-200 bg-slate-100/80">
                                    {{-- Old columns: Name, Variant, Committed qty, Available qty, Price, Actions --}}
                                    <th class="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Name</th>
                                    <th class="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 w-40">GIA</th>
                                    <th class="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Variant</th>
                                    <th class="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 text-right tabular-nums">Qty</th>
                                    <th class="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 text-right tabular-nums">Committed qty</th>
                                    <th class="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 text-right tabular-nums">Available qty</th>
                                    <th class="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 text-right">Price</th>
                                    <th class="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 w-24">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(item, i) in order.line_items" :key="i">
                                    <tr class="border-b border-slate-100 last:border-b-0 hover:bg-slate-50/50">
                                        <td class="px-3 py-2 text-slate-800"><span x-text="item.title"></span><span x-show="item.custom_item" class="text-slate-500 ml-1">(custom item)</span></td>
                                        <td class="px-3 py-2 text-slate-600" x-text="item.gia_report || '—'"></td>
                                        {{-- Old: <td class="px-3 py-2 text-slate-600" x-text="(item.variant_options && item.variant_options.length > 0) ? item.variant_options.map(o => o.name + ': ' + o.value).join(', ') : '—'"></td> --}}
                                        <td class="px-3 py-2 text-slate-600" x-text="variantLabel(item)"></td>
                                        <td class="px-3 py-2 text-slate-600 text-right tabular-nums" x-text="item.quantity ?? 0"></td>
                                        <td class="px-3 py-2 text-slate-600 text-right tabular-nums" x-text="item.custom_item ? '—' : (item.committed_quantity ?? 0)"></td>
                                        <td class="px-3 py-2 text-slate-600 text-right tabular-nums" x-text="item.custom_item ? '—' : availableQuantity(item)"></td>
                                        <td class="px-3 py-2 text-slate-600 text-right tabular-nums" x-text="formatMoney(item.unit_price, item.currency)"></td>
                                        <td class="px-3 py-2"><a x-show="shopDomain && item.product_id" :href="'https://' + shopDomain + '/admin/products/' + (item.product_id && item.product_id.split('/').pop())" target="_blank" rel="noopener noreferrer" @click="logButtonClick('view-in-shopify-product-shipped', { order_id: order.id, product_id: item.product_id })" class="inline-flex items-center gap-1 px-2 py-1 rounded border border-black bg-transparent text-black text-[11px] font-medium hover:bg-black hover:text-white">
                                            <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 01-8 0"/></svg>
                                            View in Shopify
                                        </a></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </template>
                <h4 class="font-medium text-slate-700 mb-1.5 text-xs mt-4 pt-4 border-t border-slate-200">Tags & note</h4>
                <p class="text-slate-600"><span class="text-slate-500">Tags: </span><span x-text="(order.tags && order.tags.length > 0) ? order.tags.join(', ') : '—'"></span></p>
                <p class="text-slate-600"><span class="text-slate-500">Note: </span><span x-text="(order.note && order.note.trim()) ? order.note : '—'"></span></p>
                <template x-if="order.webshipper">
                    <div>
                        <h4 class="font-medium text-slate-700 mb-1.5 text-xs mt-4 pt-4 border-t border-slate-200">Shipping (Webshipper)</h4>
                        <p><span class="text-slate-500">Status: </span><span x-text="order.webshipper?.status || '—'"></span></p>
                        <p x-show="order.webshipper?.carrier_names?.length > 0"><span class="text-slate-500">Carrier(s): </span><span x-text="(order.webshipper?.carrier_names || []).join(', ')"></span></p>
                        <p x-show="order.webshipper?.tracking_numbers?.length > 0"><span class="text-slate-500">Tracking: </span><span x-text="(order.webshipper?.tracking_numbers || []).join(', ')"></span></p>
                    </div>
                </template>
            </div>
            <div class="flex flex-col sm:flex-row gap-6 sm:gap-8 pt-2 border-t border-slate-200">
                <div><h4 class="font-medium text-slate-700 mb-2">Billing address</h4><p class="text-slate-600 whitespace-pre-line" x-text="formatAddress(order.billing_address)"></p></div>
                <div><h4 class="font-medium text-slate-700 mb-2">Shipping address</h4><p class="text-slate-600 whitespace-pre-line" x-text="formatAddress(order.shipping_address)"></p></div>
            </div>
        </div>
    </td>
</tr>
</tbody>
