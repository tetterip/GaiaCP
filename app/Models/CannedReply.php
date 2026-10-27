<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A ready-made answer the admins insert into a ticket reply (Settings → Canned replies); see App\Actions\Tickets::fill(). */
#[Fillable(['title', 'body'])]
class CannedReply extends Model {}
