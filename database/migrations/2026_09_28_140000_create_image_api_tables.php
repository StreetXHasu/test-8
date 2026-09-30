<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates users, shared image blobs and per-user images.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email', 180);
            $table->string('password');
            $table->unique('email', 'uniq_users_email');
        });

        Schema::create('image_blobs', static function (Blueprint $table): void {
            $table->string('content_hash', 64)->primary();
            $table->string('storage_key', 80);
            $table->string('mime_type', 32);
            $table->integer('size_bytes');
            $table->integer('width');
            $table->integer('height');
            $table->integer('reference_count');
        });

        Schema::create('images', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('blob_hash', 64);
            $table->string('original_filename', 180);
            $table->timestamp('created_at', 0);

            $table->index(['user_id', 'created_at', 'id'], 'idx_images_user_created');
            $table->unique(['user_id', 'blob_hash'], 'uniq_images_user_blob');

            $table->foreign('user_id', 'fk_images_user')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('blob_hash', 'fk_images_blob')
                ->references('content_hash')->on('image_blobs');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images');
        Schema::dropIfExists('image_blobs');
        Schema::dropIfExists('users');
    }
};
