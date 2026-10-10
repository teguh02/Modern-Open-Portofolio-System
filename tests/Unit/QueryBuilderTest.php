<?php

declare(strict_types=1);

namespace Mops\Tests\Unit;

use Mops\Core\Blueprint;
use Mops\Core\Database;
use Mops\Core\Schema;
use Mops\Tests\TestCase;

final class QueryBuilderTest extends TestCase
{
    private Database $db;

    private Schema $schema;

    protected function setUp(): void
    {
        $this->db = new Database(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->schema = new Schema($this->db);

        $this->schema->create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 191);
            $table->string('slug', 191);
            $table->string('status', 32)->default('draft');
            $table->boolean('featured')->default(0);
            $table->integer('sort_order')->default(0);
            $table->text('tech_stack')->nullable();
            $table->datetime('created_at')->nullable();
            $table->unique('slug');
        });
    }

    public function test_schema_creates_table_with_columns_and_indexes(): void
    {
        $this->assertTrue($this->schema->hasTable('projects'));
        $this->assertTrue($this->schema->hasColumn('projects', 'tech_stack'));
        $this->assertFalse($this->schema->hasColumn('projects', 'missing'));
    }

    public function test_insert_find_update_and_delete(): void
    {
        $id = $this->db->table('projects')->insert([
            'title' => 'MOPS',
            'slug' => 'mops',
            'status' => 'published',
            'featured' => 1,
            'created_at' => '2026-01-01 10:00:00',
        ]);

        $this->assertGreaterThan(0, $id);

        $row = $this->db->table('projects')->where('id', $id)->first();
        $this->assertSame('MOPS', $row['title']);
        $this->assertSame(1, (int) $row['featured']);

        $this->db->table('projects')->where('id', $id)->update(['title' => 'MOPS CMS']);
        $this->assertSame('MOPS CMS', $this->db->table('projects')->where('id', $id)->first()['title']);

        $this->assertSame(1, $this->db->table('projects')->where('id', $id)->delete());
        $this->assertNull($this->db->table('projects')->where('id', $id)->first());
    }

    public function test_ordering_limiting_and_pagination(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $index => $slug) {
            $this->db->table('projects')->insert([
                'title' => strtoupper($slug),
                'slug' => $slug,
                'status' => 'published',
                'sort_order' => $index,
                'created_at' => '2026-01-0' . ($index + 1) . ' 08:00:00',
            ]);
        }

        $ordered = $this->db->table('projects')->orderBy('sort_order', 'desc')->limit(2)->get();
        $this->assertCount(2, $ordered);
        $this->assertSame('D', $ordered[0]['title']);
        $this->assertSame('C', $ordered[1]['title']);

        $page = $this->db->table('projects')->orderBy('sort_order')->paginate(2, 2)->get();
        $this->assertSame('C', $page[0]['title']);
        $this->assertSame('D', $page[1]['title']);
    }

    public function test_where_variants_and_aggregates(): void
    {
        $this->db->table('projects')->insert(['title' => 'One', 'slug' => 'one', 'status' => 'published', 'created_at' => '2026-01-01 00:00:00']);
        $this->db->table('projects')->insert(['title' => 'Two', 'slug' => 'two', 'status' => 'draft', 'created_at' => '2026-01-02 00:00:00']);
        $this->db->table('projects')->insert(['title' => 'Three', 'slug' => 'three', 'status' => 'published', 'created_at' => '2026-01-03 00:00:00']);

        $this->assertSame(2, $this->db->table('projects')->where('status', 'published')->count());
        $this->assertSame(3, $this->db->table('projects')->count());
        $this->assertSame(2, $this->db->table('projects')->whereIn('status', ['published'])->count());
        $this->assertSame(1, $this->db->table('projects')->whereLike('title', '%wo%')->count());
        // tech_stack was never filled in, so nothing is NOT NULL yet.
        $this->assertSame(0, $this->db->table('projects')->whereNotNull('tech_stack')->count());
        $this->assertTrue($this->db->table('projects')->where('status', 'draft')->exists());
        $this->assertFalse($this->db->table('projects')->where('status', 'archived')->exists());
        // Empty IN list must never match everything.
        $this->assertSame(0, $this->db->table('projects')->whereIn('status', [])->count());
    }

    public function test_generated_sql_is_parameterised(): void
    {
        $query = $this->db->table('projects')->where('status', 'published')->whereIn('id', [1, 2]);

        $this->assertStringContainsString('?', $query->toSelectSql());
        $this->assertStringNotContainsString('published', $query->toSelectSql());
        $this->assertSame(['published', 1, 2], $query->bindings());
    }

    public function test_transaction_rolls_back_on_failure(): void
    {
        try {
            $this->db->transaction(function (Database $db): void {
                $db->table('projects')->insert(['title' => 'Rollback', 'slug' => 'rollback', 'created_at' => '2026-01-01 00:00:00']);

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, $this->db->table('projects')->count());
    }

    public function test_transaction_if_needed_reuses_an_active_transaction(): void
    {
        $this->db->transaction(function (Database $db): void {
            $db->transactionIfNeeded(function (Database $inner): void {
                $inner->table('projects')->insert(['title' => 'Nested', 'slug' => 'nested', 'created_at' => '2026-01-01 00:00:00']);
            });
        });

        $this->assertSame(1, $this->db->table('projects')->count());
    }

    public function test_unique_index_is_enforced(): void
    {
        $this->db->table('projects')->insert(['title' => 'One', 'slug' => 'same', 'created_at' => '2026-01-01 00:00:00']);

        $this->expectException(\PDOException::class);
        $this->db->table('projects')->insert(['title' => 'Two', 'slug' => 'same', 'created_at' => '2026-01-01 00:00:00']);
    }
}
