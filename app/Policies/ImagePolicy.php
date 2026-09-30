<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Image;
use App\Models\User;

final class ImagePolicy
{
    public function view(User $user, Image $image): bool
    {
        return $image->user_id === $user->id;
    }

    public function delete(User $user, Image $image): bool
    {
        return $this->view($user, $image);
    }
}
