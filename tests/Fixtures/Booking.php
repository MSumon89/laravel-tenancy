<?php

namespace BuildWithSmk\Tenancy\Tests\Fixtures;

use BuildWithSmk\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use BelongsToTenant;

    protected $guarded = [];
}
