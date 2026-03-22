<?php
declare(strict_types=1);
namespace Semitexa\Cache\Internal;

final readonly class TagSet
{
    /** @var list<string> */
    public array $tags;

    /** @param list<string> $tags */
    public function __construct(array $tags)
    {
        $normalized = array_values(array_unique(
            array_map(static fn(string $t) => strtolower(trim($t)), $tags)
        ));
        sort($normalized);
        $this->tags = $normalized;
    }

    /** @return list<string> */
    public function values(): array
    {
        return $this->tags;
    }

    public function isEmpty(): bool
    {
        return $this->tags === [];
    }
}
