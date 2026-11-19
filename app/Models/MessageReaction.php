<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** Composite-key pivot row. Written with query builder inserts/deletes. */
class MessageReaction extends Eloquent
{
    protected $table = 'message_reactions';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;
}
