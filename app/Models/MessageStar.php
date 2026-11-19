<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** Composite-key pivot row. Written with query builder inserts/deletes. */
class MessageStar extends Eloquent
{
    protected $table = 'message_stars';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;
}
