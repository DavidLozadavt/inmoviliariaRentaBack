<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// NUEVO: canal público de estado de Lyra — accesible sin login
Broadcast::channel('lyra.status', function () {
    return true; // público, cualquier cliente puede escuchar
});

// NUEVO: canal público de sesiones de Lyra para el dashboard admin
Broadcast::channel('lyra.sessions', function () {
    return true;
});
