<?php

use Illuminate\Support\Facades\Broadcast;

// Each user only hears their own notifications.
Broadcast::channel('App.Models.User.{id}', fn ($user, $id) => (int) $user->id === (int) $id);
