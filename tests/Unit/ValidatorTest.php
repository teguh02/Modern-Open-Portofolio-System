<?php

declare(strict_types=1);

namespace Mops\Tests\Unit;

use Mops\Core\Validator;
use Mops\Tests\TestCase;

final class ValidatorTest extends TestCase
{
    public function test_required_and_max_rules_fail_with_messages(): void
    {
        $validator = Validator::make(
            ['title' => ''],
            ['title' => 'required|max:10'],
            ['title' => 'Judul'],
        );

        $this->assertTrue($validator->fails());
        $this->assertSame('Judul wajib diisi.', $validator->firstError());
    }

    public function test_valid_input_passes_and_is_normalised(): void
    {
        $validator = Validator::make([
            'title' => '  Proyek Baru  ',
            'budget' => '1500000',
            'published' => 'on',
            'email' => 'admin@example.test',
            'started_at' => '2026-05-01',
        ], [
            'title' => 'required|max:50',
            'budget' => 'required|integer',
            'published' => 'nullable|boolean',
            'email' => 'required|email',
            'started_at' => 'nullable|date',
        ]);

        $this->assertTrue($validator->passes(), implode(' | ', $validator->errorList()));
        $this->assertSame('Proyek Baru', $validator->value('title'));
        $this->assertSame(1500000, $validator->value('budget'));
        $this->assertSame(1, $validator->value('published'));
        $this->assertSame('2026-05-01', $validator->value('started_at'));
    }

    public function test_nullable_empty_field_becomes_null(): void
    {
        $validator = Validator::make(['end_date' => ''], ['end_date' => 'nullable|date']);

        $this->assertTrue($validator->passes());
        $this->assertNull($validator->value('end_date'));
    }

    public function test_in_and_confirmed_rules(): void
    {
        $validator = Validator::make([
            'status' => 'archived',
            'password' => 'secret123',
            'password_confirmation' => 'different',
        ], [
            'status' => 'required|in:draft,published',
            'password' => 'required|min:8|confirmed',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('status', $validator->errors());
        $this->assertArrayHasKey('password', $validator->errors());
    }

    public function test_url_rule_accepts_root_relative_links(): void
    {
        $absolute = Validator::make(['link' => 'https://mops.test/proyek'], ['link' => 'required|url']);
        $relative = Validator::make(['link' => '/proyek'], ['link' => 'required|url']);

        $this->assertTrue($absolute->passes());
        $this->assertTrue($relative->passes());
    }

    public function test_invalid_email_is_rejected(): void
    {
        $validator = Validator::make(['email' => 'not-an-email'], ['email' => 'required|email']);

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('email yang valid', $validator->firstError());
    }
}
