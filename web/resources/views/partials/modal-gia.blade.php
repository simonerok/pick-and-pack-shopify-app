<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" role="dialog" aria-modal="true" aria-labelledby="gia-confirm-title" x-show="giaConfirm != null" x-cloak x-transition>
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-5 text-sm" @click.stop>
        <h2 id="gia-confirm-title" class="font-semibold text-slate-800 mb-2">Add GIA to Business Central</h2>
        <p class="text-slate-600 mb-2">This will create a new <strong>Comment</strong> line on the linked Business Central sales order with the following details:</p>
        <ul class="text-slate-600 mb-4 list-disc list-inside space-y-1" x-show="giaConfirm">
            <li>Order: <strong x-text="giaConfirm?.orderName"></strong> (BC #<span x-text="giaConfirm?.bcOrderNumber"></span>)</li>
            <li>Line type: Comment</li>
            <li>Description: <strong x-text="giaConfirm?.giaNumber"></strong></li>
        </ul>
        <p class="text-slate-500 text-xs mb-4">The new line will appear on the sales order in Business Central. You can edit or delete it there if needed.</p>
        <div class="flex justify-end gap-2">
            <button type="button" @click="giaConfirm = null" class="inline-flex items-center justify-center gap-1 min-w-[11rem] px-3 py-1.5 rounded-md border border-black bg-transparent text-black text-sm font-medium hover:bg-black hover:text-white transition-colors">Cancel</button>
            <button type="button" @click="handleAddGiaConfirm()" :disabled="!mutationsEnabled" class="inline-flex items-center justify-center gap-1 min-w-[11rem] px-3 py-1.5 rounded-md border border-black bg-transparent text-black text-sm font-medium hover:bg-black hover:text-white transition-colors disabled:opacity-60 disabled:cursor-not-allowed">Confirm and add line</button>
        </div>
    </div>
</div>