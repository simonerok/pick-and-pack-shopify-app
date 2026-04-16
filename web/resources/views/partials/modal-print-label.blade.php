<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" role="dialog" aria-modal="true" aria-labelledby="print-label-confirm-title" x-show="printLabelConfirmWsOrderId != null" x-cloak x-transition>
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-5 text-sm" @click.stop>
        <h2 id="print-label-confirm-title" class="font-semibold text-slate-800 mb-2">Print shipping label</h2>
        <p class="text-slate-600 mb-4">Ship this order in Webshipper and open the label PDF in a new tab? If no shipment exists yet, one will be created first.</p>
        <div class="flex justify-end gap-2">
            <button type="button" @click="printLabelConfirmWsOrderId = null" class="inline-flex items-center justify-center min-w-[11rem] px-3 py-1.5 rounded-md border border-slate-300 text-slate-700 hover:bg-slate-50">Cancel</button>
            <button type="button" @click="handlePrintLabel()" class="inline-flex items-center justify-center min-w-[11rem] px-3 py-1.5 rounded-md bg-amber-600 text-white hover:bg-amber-700">Ship and print label</button>
        </div>
    </div>
</div>