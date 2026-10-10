<?php

declare(strict_types=1);

namespace Mops\Tests\Unit;

use Mops\Core\Str;
use Mops\Tests\TestCase;

/**
 * Date labels feed `period`, `issued_label` and `published_label`, which every
 * theme prints. Month names are inserted into the format *before* date() runs,
 * so a name like "Mar" must survive date()'s own tokens (M, a, r).
 */
final class StrTest extends TestCase
{
    public function test_month_names_are_printed_verbatim_instead_of_expanding_date_tokens(): void
    {
        $this->assertSame('Mar 2021', Str::date('2021-03-01', 'M Y', 'en'));
        $this->assertSame('01 Mar 2021', Str::date('2021-03-01', 'd M Y', 'en'));
        $this->assertSame('Agu 2021', Str::date('2021-08-01', 'M Y', 'id'));
        $this->assertSame('Des 2021', Str::date('2021-12-09', 'M Y', 'id'));
    }

    public function test_full_month_names_use_the_locale(): void
    {
        $this->assertSame('March 2021', Str::date('2021-03-01', 'F Y', 'en'));
        $this->assertSame('Desember 2021', Str::date('2021-12-09', 'F Y', 'id'));
    }

    public function test_unknown_locale_falls_back_to_english(): void
    {
        $this->assertSame('May 2021', Str::date('2021-05-01', 'M Y', 'fr'));
    }

    public function test_it_never_leaks_raw_timestamp_noise(): void
    {
        $label = Str::date('2021-03-01', 'd M Y', 'en');

        $this->assertStringNotContainsString('+0000', $label);
        $this->assertStringNotContainsString('am', $label);
        $this->assertStringNotContainsString('Maram', $label);
    }

    public function test_empty_and_invalid_dates_render_empty(): void
    {
        $this->assertSame('', Str::date(null));
        $this->assertSame('', Str::date(''));
        $this->assertSame('', Str::date('0000-00-00'));
        $this->assertSame('', Str::date('bukan-tanggal'));
    }

    public function test_date_range_uses_the_same_formatting(): void
    {
        $this->assertSame('Jun 2018 — Feb 2021', Str::dateRange('2018-06-01', '2021-02-28'));
        $this->assertSame('Mar 2021 — Present', Str::dateRange('2021-03-01', null, 'Present'));
        $this->assertSame('', Str::dateRange(null, '2021-01-01'));
    }

    public function test_slug_handles_accents_and_fallback(): void
    {
        $this->assertSame('teguh-rijanandi', Str::slug('Teguh Rijanandi'));
        $this->assertSame('nadia-prameswari', Str::slug('Nadía Prameswari'));
        $this->assertSame('portfolio', Str::slug('', 'portfolio'));
    }
}
