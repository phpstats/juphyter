<?php

declare(strict_types=1);

namespace Juphyter\Display;

use Countable;

/**
 * One value's representations, as Jupyter's `display_data` carries them: an
 * ordered map of MIME type to data, plus a per-type metadata map.
 *
 * The bundle is immutable; every `with*()` returns a new one. Order is
 * insertion order, richest first by convention, and re-setting a type keeps its
 * original position so a formatter can refine a representation without moving
 * it. Jupyter front ends choose for themselves, but nbconvert and Quarto walk
 * the bundle in order, and a stable order also keeps the tests honest.
 *
 * Data is `mixed` rather than `string` on purpose: `application/json` and the
 * Vega-Lite types carry a decoded structure, which the transport encodes along
 * with the rest of the message, and `image/svg+xml` carries markup.
 */
final class MimeBundle implements Countable
{
    public const string PLAIN = 'text/plain';

    public const string HTML = 'text/html';

    public const string MARKDOWN = 'text/markdown';

    public const string JSON = 'application/json';

    public const string SVG = 'image/svg+xml';

    /**
     * JupyterLab's bundled renderer defines Vega-Lite v3, v4 and v5 and has no
     * v6 constant, so a v6 spec is published under the newest type the renderer
     * knows. See the plot-representation table in the plan.
     */
    public const string VEGALITE = 'application/vnd.vegalite.v5+json';

    /**
     * @param  array<string, mixed>  $data  MIME type => representation.
     * @param  array<string, array<string, mixed>>  $metadata  MIME type => metadata.
     */
    public function __construct(
        private readonly array $data = [],
        private readonly array $metadata = [],
    ) {}

    /** The one representation every bundle has to carry. */
    public static function plain(string $text): self
    {
        return new self([self::PLAIN => $text]);
    }

    /**
     * @param  array<string, mixed>|null  $metadata  Replaces this type's metadata when given.
     */
    public function with(string $type, mixed $data, ?array $metadata = null): self
    {
        $types = $this->data;
        $types[$type] = $data;

        $all = $this->metadata;

        if ($metadata !== null) {
            $all[$type] = $metadata;
        }

        return new self($types, $all);
    }

    public function without(string $type): self
    {
        $data = $this->data;
        $metadata = $this->metadata;
        unset($data[$type], $metadata[$type]);

        return new self($data, $metadata);
    }

    public function has(string $type): bool
    {
        return array_key_exists($type, $this->data);
    }

    public function get(string $type): mixed
    {
        return $this->data[$type] ?? null;
    }

    /** @return list<string> The MIME types, in order. */
    public function types(): array
    {
        return array_keys($this->data);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /** @return array<string, array<string, mixed>> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }
}
