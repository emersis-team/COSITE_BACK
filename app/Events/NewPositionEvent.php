<?php

namespace App\Events;

use App\User;
use App\Models\UserPosition;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

class NewPositionEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $position = null;
    public $contacts = null;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(UserPosition $position, array $contacts)
    {
        $this->position = $position;
        $this->contactos = $contacts;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        $users_to_broadcast[] = array();

        foreach($this->contactos as $x => $contactId){

            $users_to_broadcast[$x] = new channel('user.' . $contactId);
        }

        //Devuelve un array con los canales de todos los contactos
        return $users_to_broadcast;
    }
}
