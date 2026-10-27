<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** An email's subject and body in a language other than the default one; read through App\Support\MailTemplates. */
#[Fillable(['key', 'locale', 'subject', 'body'])]
class EmailTemplateTranslation extends Model {}
