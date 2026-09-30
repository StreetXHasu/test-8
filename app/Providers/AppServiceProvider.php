<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exceptions\ApiException;
use App\Models\Image;
use App\Models\User;
use App\Security\JwtCodec;
use App\Storage\ObjectStore;
use App\Storage\S3ObjectStorage;
use Carbon\FactoryImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Psr\Clock\ClockInterface;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            ConnectionInterface::class,
            static fn (Application $app): ConnectionInterface => $app->make('db')->connection(),
        );

        $this->app->singleton(
            ClockInterface::class,
            static fn (): ClockInterface => FactoryImmutable::getDefaultInstance(),
        );

        $this->app->singleton(JwtCodec::class, static fn (Application $app): JwtCodec => new JwtCodec(
            (string) config('jwt.secret'),
            (int) config('jwt.ttl'),
            $app->make(ClockInterface::class),
        ));

        $this->app->singleton(S3ObjectStorage::class, static function (): S3ObjectStorage {
            /** @var FilesystemAdapter $disk */
            $disk = Storage::disk('images');

            return new S3ObjectStorage($disk);
        });
        $this->app->alias(S3ObjectStorage::class, ObjectStore::class);
    }

    public function boot(): void
    {
        $this->app->make(JwtCodec::class);

        Route::bind('image', static function (string $value): Image {
            $user = auth()->user();
            if (!$user instanceof User) {
                throw new ApiException('Unauthorized', 401);
            }

            $image = Image::query()->with('blob')->whereKey($value)->where('user_id', $user->id)->first();
            if (!$image instanceof Image) {
                throw new ApiException('Image not found', 404);
            }

            return $image;
        });
    }
}
