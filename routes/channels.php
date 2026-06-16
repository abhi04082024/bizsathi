<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('business.{businessId}', function ($user, $businessId) {
    return $user->businesses->contains($businessId);
});

Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
