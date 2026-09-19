<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use NoriaLabs\Platform\Money\MoneyCast;

class Priced extends Model
{
    protected $table = 'priced';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['total' => MoneyCast::class];
    }
}
