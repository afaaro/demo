<?php
declare(strict_types=1);

namespace System\Library;

/**
 * A named collection of HTML fragments.
 *
 * Blocks are rendered in the order they were added. A block can be
 * appended to from anywhere — a controller, a partial, or a view —
 * and read once by the layout.
 *
 *   $blocks->add('sidebar', '<p>Hello</p>');
 *   $blocks->add('sidebar', $view->partial('widgets/recent'));
 *   echo $blocks->get('sidebar');   // both fragments, in order
 */

class Block
{
    /** @var array<string, string[]> Block name → ordered list of HTML fragments */
    protected array $blocks = [];

    /**
     * Add HTML to the end of a block.
     */
    public function add(string $name, string $html): static
    {
        $this->blocks[$name][] = $html;
        return $this;
    }

    /**
     * Add HTML to the *start* of a block.
     * Useful for "before content" hooks.
     */
    public function prepend(string $name, string $html): static
    {
        if (!isset($this->blocks[$name])) {
            $this->blocks[$name] = [];
        }
        array_unshift($this->blocks[$name], $html);
        return $this;
    }

    /**
     * Return the concatenated HTML for a block, or '' if empty.
     */
    public function get(string $name): string
    {
        return implode('', $this->blocks[$name] ?? []);
    }

    /**
     * True when a block exists and has at least one non-empty fragment.
     */
    public function has(string $name): bool
    {
        if (empty($this->blocks[$name])) {
            return false;
        }
        foreach ($this->blocks[$name] as $fragment) {
            if ($fragment !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Replace a block's contents entirely.
     */
    public function set(string $name, string $html): static
    {
        $this->blocks[$name] = [$html];
        return $this;
    }

    /**
     * Remove a block entirely.
     */
    public function remove(string $name): static
    {
        unset($this->blocks[$name]);
        return $this;
    }

    /**
     * Get all defined block names.
     *
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->blocks);
    }

    /**
     * Clear all blocks. Useful between renders or in tests.
     */
    public function clear(): static
    {
        $this->blocks = [];
        return $this;
    }
}