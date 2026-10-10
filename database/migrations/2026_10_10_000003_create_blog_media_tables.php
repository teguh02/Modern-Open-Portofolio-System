<?php

declare(strict_types=1);

use Mops\Core\Blueprint;
use Mops\Core\Migration;

/**
 * Media library + blog posts. Images are files on disk; only the path is stored.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('media', function (Blueprint $table): void {
            $table->id();
            $table->string('path', 255);
            $table->string('filename', 191);
            $table->string('original_name', 191)->default('');
            $table->string('mime', 120)->default('');
            $table->string('extension', 16)->default('');
            $table->integer('size')->default(0);
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->string('alt', 191)->default('');
            $table->string('title', 191)->default('');
            $table->foreignId('uploaded_by')->nullable();
            $table->datetime('created_at');
            $table->unique('path');
            $table->index('created_at');
        });

        $this->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 191);
            $table->string('slug', 191);
            $table->string('excerpt', 255)->default('');
            $table->text('content')->nullable();
            $table->string('cover_image', 255)->nullable();
            $table->string('status', 32)->default('draft');
            $table->datetime('published_at')->nullable();
            $table->foreignId('author_id')->nullable();
            $table->text('tags')->nullable();
            $table->timestamps();
            $table->unique('slug');
            $table->index('status');
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        $this->drop('posts');
        $this->drop('media');
    }
};
