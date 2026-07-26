<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data;

/**
 * One bad row of an import file, with enough context for the teacher to fix
 * the spreadsheet without guessing.
 */
final readonly class ImportRowError
{
    /**
     * @param  int  $line  1-based line number in the source file, header included.
     * @param  array<int, string>  $messages
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public int $line,
        public array $messages,
        public ?string $column = null,
        public array $raw = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'column' => $this->column,
            'messages' => $this->messages,
            'raw' => $this->raw,
        ];
    }

    public function toString(): string
    {
        return sprintf(
            'Line %d%s: %s',
            $this->line,
            $this->column !== null ? ' ['.$this->column.']' : '',
            implode('; ', $this->messages)
        );
    }
}
