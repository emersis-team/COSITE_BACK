<?php

namespace App\Listeners;

use App\User;
use App\Events\NewMessage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Notifications\NewMessageNotification;

class SendNewMessageListener  implements ShouldQueue
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  object  $event
     * @return void
     */
    public function handle(NewMessage $event)
    {

        $users_to_notify[] = $event->message->sender_id;
        $users_to_notify[] = $event->message->receiver_id;

        $users = User::whereIn('id',$users_to_notify)->get();

        Notification::send($users, new NewMessageNotification($event->message));
    }
}
