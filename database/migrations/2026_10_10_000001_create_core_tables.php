<?php

declare(strict_types=1);

use Mops\Core\Blueprint;
use Mops\Core\Migration;

/**
 * Core tables: users, settings, activity log.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 191);
            $table->string('password_hash', 255);
            $table->string('role', 32)->default('admin');
            $table->string('avatar', 255)->nullable();
            $table->datetime('last_login_at')->nullable();
            $table->timestamps();
            $table->unique('email');
        });

        $this->create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 191);
            $table->text('value')->nullable();
            $table->unique('key');
        });

        $this->create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->foreignId('subject_id')->nullable();
            $table->text('description')->nullable();
            $table->datetime('created_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        $this->drop('activity_log');
        $this->drop('settings');
        $this->drop('users');
    }
};
