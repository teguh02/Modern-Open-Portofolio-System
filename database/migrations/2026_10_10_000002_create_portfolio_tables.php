<?php

declare(strict_types=1);

use Mops\Core\Blueprint;
use Mops\Core\Migration;

/**
 * Portfolio data model: profile, experience, education, skills,
 * certifications, awards, projects.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->create('profile', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name', 160)->default('');
            $table->string('headline', 191)->default('');
            $table->string('short_bio', 255)->default('');
            $table->text('bio')->nullable();
            $table->string('photo', 255)->nullable();
            $table->string('cover_image', 255)->nullable();
            $table->string('location', 160)->default('');
            $table->string('email', 191)->default('');
            $table->string('phone', 64)->default('');
            $table->string('website', 255)->default('');
            $table->string('availability', 160)->default('');
            $table->string('resume_file', 255)->nullable();
            $table->text('focus_areas')->nullable();
            $table->text('social_links')->nullable();
            $table->text('stats')->nullable();
            $table->string('meta_title', 191)->default('');
            $table->string('meta_description', 255)->default('');
            $table->timestamps();
        });

        $this->create('experiences', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 160);
            $table->string('company', 160);
            $table->string('company_url', 255)->nullable();
            $table->string('location', 160)->default('');
            $table->string('employment_type', 64)->default('Full-time');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(0);
            $table->text('description')->nullable();
            $table->text('highlights')->nullable();
            $table->string('logo', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index('start_date');
        });

        $this->create('educations', function (Blueprint $table): void {
            $table->id();
            $table->string('school', 160);
            $table->string('degree', 160)->default('');
            $table->string('field_of_study', 160)->default('');
            $table->string('location', 160)->default('');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(0);
            $table->string('grade', 64)->default('');
            $table->text('description')->nullable();
            $table->string('logo', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index('end_date');
        });

        $this->create('skills', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('category', 120)->default('');
            $table->integer('level')->default(0);
            $table->integer('years')->nullable();
            $table->string('icon', 120)->default('');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_featured')->default(0);
            $table->timestamps();
            $table->index('sort_order');
        });

        $this->create('certifications', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191);
            $table->string('issuer', 160)->default('');
            $table->date('issued_date');
            $table->date('expiry_date')->nullable();
            $table->string('credential_id', 160)->default('');
            $table->string('credential_url', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('logo', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index('issued_date');
        });

        $this->create('awards', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 191);
            $table->string('issuer', 160)->default('');
            $table->integer('year');
            $table->text('description')->nullable();
            $table->string('url', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index('year');
        });

        $this->create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 191);
            $table->string('slug', 191);
            $table->string('summary', 255)->default('');
            $table->text('description')->nullable();
            $table->string('thumbnail', 255)->nullable();
            $table->text('gallery')->nullable();
            $table->text('tech_stack')->nullable();
            $table->string('role', 160)->default('');
            $table->string('client', 160)->default('');
            $table->string('project_url', 255)->nullable();
            $table->string('repo_url', 255)->nullable();
            $table->date('started_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->string('status', 32)->default('draft');
            $table->boolean('featured')->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique('slug');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        foreach (['projects', 'awards', 'certifications', 'skills', 'educations', 'experiences', 'profile'] as $table) {
            $this->drop($table);
        }
    }
};
