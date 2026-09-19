<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Auth;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use NoriaLabs\Platform\Platform;

/**
 * @property string $id
 * @property string $identifier
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon|null $created_at
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
class OtpChallenge extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return Platform::table('otp_challenges');
    }

    public function getConnectionName(): ?string
    {
        return Platform::connection() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
