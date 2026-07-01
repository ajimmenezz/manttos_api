<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventComment extends Model
{
    use SoftDeletes;

    protected $fillable = ['event_id', 'user_id', 'parent_id', 'body'];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function parent()
    {
        return $this->belongsTo(EventComment::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(EventComment::class, 'parent_id')->orderBy('created_at');
    }

    /** Usuarios arrobados en este comentario. */
    public function mentionedUsers()
    {
        return $this->belongsToMany(User::class, 'event_comment_mentions', 'comment_id', 'user_id');
    }
}
