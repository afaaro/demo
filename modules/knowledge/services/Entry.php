<?php
declare(strict_types=1);

namespace Module\Knowledge\Services;

final class Entry
{
    public ?int    $id         = null;
    public ?int    $domainId   = null;
    public string  $slug       = '';
    public string  $status     = 'draft';
    public string  $sourceUrl  = '';
    public ?int    $authorId   = null;
    public ?string $createdAt  = null;
    public ?string $updatedAt  = null;

    public ?string $domainName = null;
    public ?string $domainSlug = null;

    /** Resolved for a locale (with fallback). */
    public string $title   = '';
    public string $aliases = '';
    public string $summary = '';
    public string $body    = '';
    public string $locale  = '';

    /**
     * All translations keyed by locale.
     *
     * @var array<string, array{title:string,aliases:string,summary:string,body:string}>
     */
    public array $translations = [];

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'domain_id'   => $this->domainId,
            'domain_name' => $this->domainName,
            'domain_slug' => $this->domainSlug,
            'slug'        => $this->slug,
            'status'      => $this->status,
            'source_url'  => $this->sourceUrl,
            'author_id'   => $this->authorId,
            'title'       => $this->title,
            'aliases'     => $this->aliases,
            'summary'     => $this->summary,
            'body'        => $this->body,
            'locale'      => $this->locale,
            'translations'=> $this->translations,
        ];
    }
}
