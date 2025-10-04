<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Public order channel for real-time order updates
Broadcast::channel('order', function () {
    return true; // Allow anyone to listen to order updates
});

// Private order channel for specific order updates
Broadcast::channel('orders.{id}', function ($user, $orderId) {
    // Allow the user who created the order to listen
    $sale = \App\Models\Sale::find($orderId);
    return $sale && (int) $user->id === (int) $sale->user_id;
});
