<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Ramsey\Uuid\Uuid;

/**
 * @property string $id
 * @property string $email
 * @property string $password
 */
#[Hidden(['password'])]
class User extends Authenticatable
{
    protected $table = 'users';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    public static function register(string $email, string $passwordHash): self
    {
        $user = new self();
        $user->id = Uuid::uuid7()->toString();
        $user->email = $email;
        $user->password = $passwordHash;

        return $user;
    }
}
