<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Marshmallow\Addressable\Traits\Addressable;

/**
 * A minimal authenticatable used by the package test suite.
 *
 * @property int $id
 * @property string $email
 * @property int|null $customer_id
 */
class User extends Authenticatable
{
    use Addressable;
    use HasFactory;

    protected $guarded = [];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(config('cart.models.customer'));
    }
}
