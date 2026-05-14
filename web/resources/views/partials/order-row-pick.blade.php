<tbody>
    <tr class="border-b border-slate-100 hover:bg-slate-50/50">
        <td class="w-10 px-2 py-2 align-middle" data-label="">
            <button type="button" @click.stop="toggleExpanded(order.id)" class="flex items-center justify-center w-5 h-5 rounded border border-black bg-transparent text-black hover:bg-black hover:text-white transition-colors" :aria-expanded="isExpanded(order.id)" :title="isExpanded(order.id) ? 'Collapse' : 'Expand'">
                <svg class="w-3 h-3 shrink-0 transition-transform" :class="isExpanded(order.id) ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path d="M9 18l6-6-6-6" />
                </svg>
            </button>
        </td>
        <td class="px-4 py-2 align-middle text-xs" data-label="Order">
            <div class="flex flex-col gap-0.5">
                <div class="text-slate-500 text-[11px]" x-text="formatDate(order.created_at)"></div>
                <span class="font-medium text-slate-800" x-text="order.name"></span>
                {{--
                Old integration labels only appeared when matched external data existed.
                <template x-if="order.business_central">
                    <span class="text-slate-600 font-normal" :title="'BC sales order: ' + (order.business_central?.number || '')" x-text="'BC: ' + (order.business_central?.number || '')"></span>
                </template>
                <template x-if="order.webshipper">
                    <span class="text-slate-600 font-normal" :title="(order.webshipper?.tracking_numbers?.length > 0 ? 'Tracking: ' + (order.webshipper.tracking_numbers.join(', ')) : 'Webshipper order #' + order.webshipper?.order_id)" x-text="'WS: ' + (order.webshipper?.status || ('#' + order.webshipper?.order_id)) + (order.webshipper?.tracking_numbers?.length > 0 ? ' (' + order.webshipper.tracking_numbers.length + ' tracking)' : '')"></span>
                </template>
                --}}
                <span class="text-slate-600 font-normal" :title="order.business_central ? ('BC sales order: ' + (order.business_central?.number || '')) : 'Placeholder for Business Central number'" x-text="order.business_central ? ('BC: ' + (order.business_central?.number || '')) : 'BC: number from BC'"></span>
                <span class="text-slate-600 font-normal" :title="order.webshipper ? (order.webshipper?.tracking_numbers?.length > 0 ? 'Tracking: ' + (order.webshipper.tracking_numbers.join(', ')) : 'Webshipper order #' + order.webshipper?.order_id) : 'Placeholder for Webshipper number'" x-text="order.webshipper ? ('WS: ' + (order.webshipper?.status || ('#' + order.webshipper?.order_id)) + (order.webshipper?.tracking_numbers?.length > 0 ? ' (' + order.webshipper.tracking_numbers.length + ' tracking)' : '')) : 'WS: number from Webshipper'"></span>
            </div>
        </td>
        <td class="px-4 py-2 text-xs" data-label="Gift">
            <template x-if="Array.isArray(order.tags) && order.tags.includes('Gift')">
                <span class="text-slate-600 font-normal inline-flex items-center" aria-label="Gift order">
                    <x-heroicon-o-gift class="w-4 h-4" />
                </span>
            </template>
            <template x-if="!Array.isArray(order.tags) || !order.tags.includes('Gift')">
                <span class="text-slate-600 font-normal">—</span>
            </template>
        </td>
        <td class="px-4 py-2 text-xs" data-label="Customer">
            <div class="text-slate-700" x-text="order.billing_address ? (order.billing_address.first_name + ' ' + order.billing_address.last_name) : '—'"></div>
            <div class="text-slate-500 truncate max-w-[180px]" x-show="order.email" x-text="order.email"></div>
        </td>
        <td class="px-4 py-2 text-slate-600 whitespace-nowrap text-xs" data-label="Ship date">
            <span x-text="order.business_central && getBCShipDate(order.business_central) ? formatDateOnly(getBCShipDate(order.business_central)) : '—'"></span>
        </td>
        <td class="px-4 py-2 text-slate-600 text-xs" data-label="Delivery" x-text="order.delivery_method || '—'"></td>
        <td class="px-4 py-2" data-label="Financial">
            <span class="inline-flex px-2 py-0.5 rounded text-[11px] font-medium" :class="order.financial_status === 'paid' ? 'bg-emerald-100 text-emerald-800' : order.financial_status === 'pending' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600'" x-text="order.financial_status"></span>
        </td>
        <td class="px-4 py-2" data-label="Fulfillment">
            <span class="inline-flex px-2 py-0.5 rounded text-[11px] font-medium" :class="order.fulfillment_status === 'fulfilled' ? 'bg-emerald-100 text-emerald-800' : order.fulfillment_status === 'partial' ? 'bg-blue-100 text-blue-800' : 'bg-slate-100 text-slate-600'" x-text="order.fulfillment_status || '—'"></span>
        </td>
        <td class="px-4 py-2 text-slate-600 text-xs" data-label="Availability">
            <span x-show="isAllProductsAvailable(order)" class="text-emerald-700">All products available</span>
            <span x-show="!isAllProductsAvailable(order) && getMissingLineItems(order).length > 0" class="text-red-700 cursor-help" :title="getMissingLineItems(order).map(item => item.custom_item ? item.title + ' × ' + item.quantity + ' (custom item)' : item.title + ' × ' + item.quantity).join('\n')" x-text="getMissingLineItems(order).length + ' product' + (getMissingLineItems(order).length !== 1 ? 's' : '') + ' missing' + (getMissingLineItems(order).some(item => item.custom_item) ? ' (custom item on order)' : '')"></span>
        </td>
        <td class="px-4 py-2 text-slate-600 text-xs tabular-nums" data-label="Available %" x-text="availabilityPercentage(order) !== null ? availabilityPercentage(order) + '%' : '—'"></td>
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
                            @click="$dispatch('close'); logButtonClick('view-in-shopify-order-pick', { order_id: order.id })"
                            class="block w-full px-4 py-2 text-start text-xs leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out">
                            View in Shopify
                        </a>

                        {{--
                        Old Webshipper action logic:
                        <a
                            x-show="order.delivery_method !== 'Pickup' && order.webshipper && (order.webshipper.order_url || (webshipperAccount && order.webshipper.order_id))"
                            :href="order.webshipper?.order_url || (webshipperAccount && order.webshipper?.order_id ? 'https://' + webshipperAccount + '.webshipper.io/ship/orders/' + order.webshipper.order_id : '#')"
                            target="_blank"
                            rel="noopener noreferrer"
                            @click="$dispatch('close'); logButtonClick('view-in-webshipper-pick', { order_id: order.id, ws_order_id: order.webshipper?.order_id })"
                            class="block w-full px-4 py-2 text-start text-xs leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out">
                            View in Webshipper
                        </a>
                        --}}
                        <a
                            x-show="order.delivery_method !== 'Pickup'"
                            :href="order.webshipper?.order_url || (webshipperAccount && order.webshipper?.order_id ? 'https://' + webshipperAccount + '.webshipper.io/ship/orders/' + order.webshipper.order_id : '#')"
                            target="_blank"
                            rel="noopener noreferrer"
                            @click="if (!(order.webshipper?.order_url || (webshipperAccount && order.webshipper?.order_id))) { $event.preventDefault(); return; } $dispatch('close'); logButtonClick('view-in-webshipper-pick', { order_id: order.id, ws_order_id: order.webshipper?.order_id })"
                            :aria-disabled="!(order.webshipper?.order_url || (webshipperAccount && order.webshipper?.order_id))"
                            :title="(order.webshipper?.order_url || (webshipperAccount && order.webshipper?.order_id)) ? 'Open order in Webshipper' : 'No matching Webshipper order found'"
                            :class="(order.webshipper?.order_url || (webshipperAccount && order.webshipper?.order_id)) ? 'block w-full px-4 py-2 text-start text-xs leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out' : 'block w-full px-4 py-2 text-start text-xs leading-5 text-gray-400 cursor-not-allowed'">
                            View in Webshipper
                        </a>

                        {{--
                        Old print label logic:
                        <a
                            href="#"
                            x-show="order.webshipper && order.delivery_method !== 'Pickup'"
                            @click.prevent="if (!mutationsEnabled || labelLoadingWsOrderId === order.webshipper?.order_id) return; $dispatch('close'); openPrintLabelConfirm(order.webshipper.order_id)"
                            :title="mutationsEnabled ? 'Ship this order in Webshipper and print the label' : 'Test mode - set APP_STATUS=Production to enable'"
                            :class="(!mutationsEnabled || labelLoadingWsOrderId === order.webshipper?.order_id) ? 'block w-full px-4 py-2 text-start text-xs leading-5 text-gray-400 cursor-not-allowed' : 'block w-full px-4 py-2 text-start text-xs leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out'">
                            <span x-text="labelLoadingWsOrderId === order.webshipper?.order_id ? 'Loading...' : 'Print label'"></span>
                        </a>
                        --}}
                        <a
                            href="#"
                            x-show="order.delivery_method !== 'Pickup'"
                            @click.prevent="if (!mutationsEnabled || !order.webshipper?.order_id || labelLoadingWsOrderId === order.webshipper?.order_id) return; $dispatch('close'); openPrintLabelConfirm(order.webshipper.order_id)"
                            :aria-disabled="!mutationsEnabled || !order.webshipper?.order_id || labelLoadingWsOrderId === order.webshipper?.order_id"
                            :title="!order.webshipper?.order_id ? 'No matching Webshipper order found' : (mutationsEnabled ? 'Ship this order in Webshipper and print the label' : 'Test mode - set APP_STATUS=Production to enable')"
                            :class="(!mutationsEnabled || !order.webshipper?.order_id || labelLoadingWsOrderId === order.webshipper?.order_id) ? 'block w-full px-4 py-2 text-start text-xs leading-5 text-gray-400 cursor-not-allowed' : 'block w-full px-4 py-2 text-start text-xs leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out'">
                            <span x-text="labelLoadingWsOrderId === order.webshipper?.order_id ? 'Loading...' : 'Print label'"></span>
                        </a>

                        <a href="#"
                            x-show="order.delivery_method === 'Pickup' && order.shopify_order_status !== 'in_progress'"
                            @click.prevent="if (!mutationsEnabled) return; $dispatch('close'); readyOrderForPickup(order.fulfillment_orders?.[0]?.node?.id)"
                            title="Ready order for pickup"
                            :class="(!mutationsEnabled) ? 'block w-full px-4 py-2 text-start text-xs leading-5 text-gray-400 cursor-not-allowed' : 'block w-full px-4 py-2 text-start text-xs leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out'">
                            Ready order for pickup
                        </a>

                        <a href="#"
                            x-show="order.delivery_method === 'Pickup' && order.shopify_order_status === 'in_progress'"
                            @click.prevent="if (!mutationsEnabled) return; $dispatch('close'); markOrderAsPickedUp(order.fulfillment_orders?.[0]?.node?.id)"
                            title="Mark order as picked up"
                            :class="(!mutationsEnabled) ? 'block w-full px-4 py-2 text-start text-xs leading-5 text-gray-400 cursor-not-allowed' : 'block w-full px-4 py-2 text-start text-xs leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out'">
                            Mark as picked up
                        </a>

                        <button
                            type="button"
                            x-show="activeTab === 'upcoming' || activeTab === 'ready-to-pack' || activeTab === 'ready-for-pickup'"
                            @click="$dispatch('close'); addOnHoldTag(order)"
                            class="block w-full px-4 py-2 text-start text-xs leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out">
                            Add on hold tag
                        </button>

                        <button
                            type="button"
                            x-show="activeTab === 'on-hold'"
                            @click="$dispatch('close'); removeOnHoldTag(order)"
                            class="block w-full px-4 py-2 text-start text-xs leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out">
                            Remove on hold tag
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>
        </td>
    </tr>
    <tr class="bg-slate-50/80" x-show="isExpanded(order.id)" x-transition>
        <td colspan="11" class="px-4 py-4">
            <div class="space-y-4 text-xs">
                <div>
                    <h4 class="font-medium text-slate-700 mb-1.5 text-xs">Products</h4>
                    <template x-if="!order.line_items || order.line_items.length === 0">
                        <p class="text-slate-500">No line items</p>
                    </template>
                    <template x-if="order.line_items && order.line_items.length > 0">
                        <div class="rounded-lg border border-slate-200 overflow-hidden product-lines-wrap">
                            <table class="w-full text-left text-xs">
                                <thead>
                                    <tr class="border-b border-slate-200 bg-slate-100/80">
                                        {{-- Old columns: Name, GIA, Variant, Committed qty, Available qty, Price, Actions --}}
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
                                            <td class="px-3 py-2 text-slate-800">
                                                <span class="flex items-center gap-2">
                                                    {{-- Old inline stock marker removed so notes do not appear beside a check/X.
                                                    <span x-show="!item.custom_item" class="flex-shrink-0" :class="(!item.custom_item && item.quantity <= availableQuantity(item)) ? 'text-emerald-600' : 'text-red-600'" :title="(!item.custom_item && item.quantity <= availableQuantity(item)) ? 'In stock or committed - can fulfill' : 'Insufficient stock'" x-text="!item.custom_item && item.quantity <= availableQuantity(item) ? '✓' : '✗'"></span>
                                                    --}}
                                                    {{-- Fixed: stock state is shown in the availability columns, not beside notes. --}}
                                                    <span>
                                                        <span x-text="item.title"></span>
                                                        <span x-show="!item.custom_item && item.quantity > availableQuantity(item) && item.expected_receipt_date" class="text-slate-500 ml-1" :title="'Expected receipt'" x-text="'(ETA: ' + formatDateOnly(item.expected_receipt_date) + ')'"></span>
                                                        <span x-show="item.custom_item" class="text-slate-500 ml-1">(custom item)</span>
                                                        <template x-if="item.properties && item.properties.length > 0">
                                                            <div class="mt-0.5 text-slate-500 text-[11px] space-y-0.5">
                                                                <template x-for="(prop, pi) in (item.properties || [])" :key="'prop-'+pi">
                                                                    <div><span class="font-medium text-slate-600" x-text="prop.name + ':'"></span> <span x-text="prop.value"></span></div>
                                                                </template>
                                                            </div>
                                                        </template>
                                                    </span>
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 align-top w-40 max-w-[10rem] overflow-hidden">
                                                {{-- Old GIA display only showed the BC input on the first BC-backed line and did not render seeded product GIA metafields. --}}
                                                <template x-if="item.gia_report">
                                                    <span class="text-slate-600" x-text="item.gia_report"></span>
                                                </template>
                                                {{--
                                                Old BC action logic:
                                                <template x-if="!item.gia_report && i === 0 && order.business_central">
                                                    <div class="flex flex-col gap-1.5 min-w-0">
                                                        <input type="text" x-model="giaInputByOrderId[order.id]" placeholder="GIA number" class="w-full min-w-0 px-2 py-1 text-[11px] border border-black rounded bg-transparent text-black focus:outline-none focus:ring-1 focus:ring-black/30" :disabled="giaLoadingOrderId === order.id">
                                                        <button type="button" @click="openGiaConfirm(order)" :disabled="!mutationsEnabled || giaLoadingOrderId === order.id || !(giaInputByOrderId[order.id] || '').trim()" class="inline-flex items-center justify-center gap-1 w-full min-w-0 px-2 py-1.5 rounded-md border border-black bg-transparent text-black text-xs font-medium hover:bg-black hover:text-white transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                                                            <span x-show="giaLoadingOrderId === order.id" class="inline-block w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                                                            <span x-show="giaLoadingOrderId !== order.id">
                                                                Add to BC
                                                            </span>
                                                        </button>
                                                    </div>
                                                </template>
                                                <template x-if="!item.gia_report && !(i === 0 && order.business_central)">
                                                    <span class="text-slate-500">&mdash;</span>
                                                </template>
                                                --}}
                                                <template x-if="!item.gia_report && i === 0">
                                                    <div class="flex flex-col gap-1.5 min-w-0">
                                                        <input type="text" x-model="giaInputByOrderId[order.id]" placeholder="GIA number" class="w-full min-w-0 px-2 py-1 text-[11px] border border-black rounded bg-transparent text-black focus:outline-none focus:ring-1 focus:ring-black/30" :disabled="!order.business_central || giaLoadingOrderId === order.id" :title="order.business_central ? 'GIA number' : 'No matching Business Central order found'">
                                                        <button type="button" @click="openGiaConfirm(order)" :disabled="!order.business_central || !mutationsEnabled || giaLoadingOrderId === order.id || !(giaInputByOrderId[order.id] || '').trim()" :title="!order.business_central ? 'No matching Business Central order found' : (mutationsEnabled ? 'Add GIA line to Business Central' : 'Test mode - set APP_STATUS=Production to enable')" class="inline-flex items-center justify-center gap-1 w-full min-w-0 px-2 py-1.5 rounded-md border border-black bg-transparent text-black text-xs font-medium hover:bg-black hover:text-white transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                                                            <span x-show="giaLoadingOrderId === order.id" class="inline-block w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                                                            <span x-show="giaLoadingOrderId !== order.id">
                                                                <!--<svg class="w-3.5 h-3.5 shrink-0 inline" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path d="M12 5v14"/><path d="M5 12h14"/></svg>-->
                                                                Add to BC
                                                            </span>
                                                        </button>
                                                    </div>
                                                </template>
                                                <template x-if="!item.gia_report && i !== 0">
                                                    <span class="text-slate-500">&mdash;</span>
                                                </template>
                                            </td>
                                            {{-- Old: <td class="px-3 py-2 text-slate-600" x-text="(item.variant_options && item.variant_options.length > 0) ? item.variant_options.map(o => o.name + ': ' + o.value).join(', ') : '—'"></td> --}}
                                            <td class="px-3 py-2 text-slate-600" x-text="variantLabel(item)"></td>
                                            <td class="px-3 py-2 text-slate-600 text-right tabular-nums" x-text="item.quantity ?? 0"></td>
                                            <td class="px-3 py-2 text-slate-600 text-right tabular-nums" x-text="item.custom_item ? '—' : (item.committed_quantity ?? 0)"></td>
                                            <td class="px-3 py-2 text-slate-600 text-right tabular-nums" x-text="item.custom_item ? '—' : availableQuantity(item)"></td>
                                            <td class="px-3 py-2 text-slate-600 text-right tabular-nums" x-text="formatMoney(item.unit_price, item.currency)"></td>
                                            <td class="px-3 py-2">
                                                <a x-show="shopDomain && item.product_id" :href="'https://' + shopDomain + '/admin/products/' + (item.product_id && item.product_id.split('/').pop())" target="_blank" rel="noopener noreferrer" @click="logButtonClick('view-in-shopify-product-pick', { order_id: order.id, product_id: item.product_id })" class="inline-flex items-center justify-center gap-1 min-w-[11rem] px-2 py-1 rounded border border-black bg-transparent text-black text-[11px] font-medium hover:bg-black hover:text-white transition-colors" title="Open product in Shopify admin">
                                                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z" />
                                                        <path d="M3 6h18" />
                                                        <path d="M16 10a4 4 0 01-8 0" />
                                                    </svg>
                                                    View in Shopify
                                                </a>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </template>
                    <h4 class="font-medium text-slate-700 mb-1.5 text-xs mt-4 pt-4 border-t border-slate-200">Tags & note</h4>
                    <div class="space-y-2">
                        <p class="text-slate-600"><span class="text-slate-500">Tags: </span><span x-text="(order.tags && order.tags.length > 0) ? order.tags.join(', ') : '—'"></span></p>
                        <p class="text-slate-600"><span class="text-slate-500">Note: </span><span x-text="(order.note && order.note.trim()) ? order.note : '—'"></span></p>
                    </div>
                    <template x-if="order.webshipper">
                        <div>
                            <h4 class="font-medium text-slate-700 mb-1.5 text-xs mt-4 pt-4 border-t border-slate-200">Shipping (Webshipper)</h4>
                            <div class="space-y-1.5 text-slate-600">
                                <p><span class="text-slate-500">Status: </span><span x-text="order.webshipper?.status || '—'"></span></p>
                                <p x-show="order.webshipper?.carrier_names?.length > 0"><span class="text-slate-500">Carrier(s): </span><span x-text="(order.webshipper?.carrier_names || []).join(', ')"></span></p>
                                <p x-show="order.webshipper?.tracking_numbers?.length > 0"><span class="text-slate-500">Tracking: </span><span x-text="(order.webshipper?.tracking_numbers || []).join(', ')"></span></p>
                            </div>
                        </div>
                    </template>
                </div>
                <div class="flex flex-col sm:flex-row gap-6 sm:gap-8 pt-2 border-t border-slate-200">
                    <div>
                        <h4 class="font-medium text-slate-700 mb-2">Billing address</h4>
                        <p class="text-slate-600 whitespace-pre-line" x-text="formatAddress(order.billing_address)"></p>
                    </div>
                    <div>
                        <h4 class="font-medium text-slate-700 mb-2">Shipping address</h4>
                        <p class="text-slate-600 whitespace-pre-line" x-text="formatAddress(order.shipping_address)"></p>
                    </div>
                </div>
            </div>
        </td>
    </tr>
</tbody>
