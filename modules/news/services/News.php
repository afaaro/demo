<?php
declare(strict_types=1);

namespace Module\News\Services;

final class News
{
    public ?int    $id          = null;
    public ?int    $categoryId  = null;
    public string  $slug        = '';
    public string  $title       = '';
    public string  $excerpt     = '';
    public string  $body        = '';
    public string  $image       = '';
    public string  $imageCaption = '';
    public string  $imageCredit  = '';
    public string  $source      = '';
    public string  $sourceUrl   = '';
    public string  $previewToken = '';
    public string  $status      = 'draft';
    public bool    $featured    = false;
    public int     $viewCount   = 0;
    public ?string $publishedAt = null;
    public ?int    $authorId    = null;
    public ?string $createdAt   = null;
    public ?string $updatedAt   = null;

    // Set by repository when joined.
    public ?string $categoryName = null;
    public ?string $categorySlug = null;
    public ?string $authorName   = null;
    /** @var list<NewsTag> */
    public array   $tags         = [];

    public function isPublished(): bool
    {
        return $this->status === 'published'
            && $this->publishedAt !== null
            && strtotime((string) $this->publishedAt) <= time();
    }

    public function isScheduled(): bool
    {
        if ($this->publishedAt === null || trim($this->publishedAt) === '') {
            return false;
        }
        $ts = strtotime($this->publishedAt);
        if ($ts === false || $ts <= time()) {
            return false;
        }
        return $this->status === 'published' || $this->status === 'draft';
    }

    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'category_id'   => $this->categoryId,
            'category_name' => $this->categoryName,
            'category_slug' => $this->categorySlug,
            'slug'          => $this->slug,
            'title'         => $this->title,
            'excerpt'       => $this->excerpt,
            'body'          => $this->body,
            'image'         => $this->image,
            'image_caption' => $this->imageCaption,
            'image_credit'  => $this->imageCredit,
            'source'        => $this->source,
            'source_url'    => $this->sourceUrl,
            'status'        => $this->status,
            'featured'      => $this->featured,
            'view_count'    => $this->viewCount,
            'published_at'  => $this->publishedAt,
            'author_id'     => $this->authorId,
            'author_name'   => $this->authorName,
        ];
    }

    public function authorLabel(): string
    {
        return trim((string) $this->authorName);
    }

    public function readingMinutes(): int
    {
        $text = trim(html_entity_decode(strip_tags($this->body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '') {
            return 0;
        }
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $count = is_array($words) ? count($words) : 0;
        if ($count < 20) {
            return $count > 0 ? 1 : 0;
        }
        return max(1, (int) ceil($count / 200));
    }
}