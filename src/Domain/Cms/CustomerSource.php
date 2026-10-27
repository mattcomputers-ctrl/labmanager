<?php
declare(strict_types=1);

namespace Chroma\Domain\Cms;

/**
 * Where customer rows come from. The real one reads the Mar-Kov CMS over
 * MSSQL (CmsConnection); tests use an in-memory fake. Rows are plain
 * arrays: ['id' => string, 'name' => string, 'active' => bool].
 */
interface CustomerSource
{
    /**
     * Every customer the source knows about.
     * @return iterable<array{id:string,name:string,active:bool}>
     */
    public function fetchCustomers(): iterable;

    /**
     * The first few rows exactly as the source returns them (for the
     * "Test connection" preview so the mapping can be checked by eye).
     * @return list<array<string,mixed>>
     */
    public function preview(int $limit = 10): array;
}
