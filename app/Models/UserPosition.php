<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPosition extends Model
{
    protected $fillable = [
        'user_id',
        'lat',
        'lon',
        'alt'
    ];

    //Get usuario for the UserPosition
    public function user()
    {
        return $this->belongsTo('App\User','user_id');
    }
}
