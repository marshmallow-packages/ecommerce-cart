<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A bare user model with neither an address book nor a customer relation, like
 * a host application whose users are not linked to the cart's customer. It
 * proves the cart connects such a user without reaching for either.
 *
 * @property int $id
 */
class Guest extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
