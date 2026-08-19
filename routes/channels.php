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
| DIQQAT: egaz-indexator da BroadcastServiceProvider config/app.php da
| IZOHGA OLINGAN edi, ya'ni bu fayl umuman yuklanmasdi. Shu holat aynan
| saqlandi — bootstrap/app.php da `channels:` berilmagan.
|
*/

Broadcast::channel('App.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
