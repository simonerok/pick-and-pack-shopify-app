<?php

// use App\Http\Controllers\ProfileController;
// use App\Http\Controllers\CreateUserController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\Api\ShopifyOrdersController;
use App\Http\Controllers\Api\WebshipperLabelController;
use App\Http\Controllers\Api\WebshipperReturnLabelController;
use App\Http\Controllers\Api\BusinessCentralLinesController;
use App\Http\Controllers\Api\WebshipperTestController;
use App\Http\Controllers\Api\BusinessCentralTestController;
use Illuminate\Support\Facades\Route;

Route::get('/', [OrdersController::class, 'index'])->middleware('auth')->name('home');

Route::middleware('auth')->prefix('api')->group(function () {
    Route::post(
        '/shopify/orders/{orderId}/on-hold',
        [ShopifyOrdersController::class, 'addOnHoldTag']
    )->name('api.shopify.orders.add-on-hold');
    Route::delete(
        '/shopify/orders/{orderId}/on-hold',
        [ShopifyOrdersController::class, 'removeOnHoldTag']
    )->name('api.shopify.orders.remove-on-hold');
    Route::get('/shopify/orders', [ShopifyOrdersController::class, 'index']);
    Route::post(
        '/shopify/orders/ready-for-pickup',
        [ShopifyOrdersController::class, 'readyOrderForPickup']
    )->name('api.shopify.orders.ready-for-pickup');
    Route::post(
        '/shopify/orders/mark-as-picked-up',
        [ShopifyOrdersController::class, 'markOrderAsPickedUp']
    )->name('api.shopify.orders.mark-as-picked-up');
    Route::get('/webshipper/label', [WebshipperLabelController::class, 'show']);
    Route::get('/webshipper/return-label', [WebshipperReturnLabelController::class, 'show']);
    Route::post('/business-central/orders/{bcOrderId}/lines', [BusinessCentralLinesController::class, 'store']);
    Route::get('/webshipper/test', [WebshipperTestController::class, 'index']);
    Route::get('/business-central/test', [BusinessCentralTestController::class, 'index']);
    Route::post('/log-button-click', function (\Illuminate\Http\Request $request) {
        $request->validate(['button' => 'required|string|max:100']);
        $props = [
            'button' => $request->input('button'),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
        if ($request->has('order_id')) {
            $props['order_id'] = $request->input('order_id');
        }

        if ($request->has('ws_order_id')) {
            $props['ws_order_id'] = $request->input('ws_order_id');
        }

        if ($request->has('product_id')) {
            $props['product_id'] = $request->input('product_id');
        }
        activity('ui')
            ->event('button_clicked')
            ->causedBy($request->user())
            ->withProperties($props)
            ->log('Button clicked: ' . $request->input('button'));
        return response()->noContent(204);
    })->name('api.log-button-click');
});

// Route::middleware('auth')->group(function () {
//     Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
//     Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
//     Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

//     Route::get('/users/create', [CreateUserController::class, 'create'])->name('users.create');
//     Route::post('/users', [CreateUserController::class, 'store'])->name('users.store');
// });

require __DIR__ . '/auth.php';
