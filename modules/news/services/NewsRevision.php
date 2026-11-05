<?php
declare(strict_types=1);

namespace Module\News\Services;

final class NewsRevision
{
    public ?int    $id         = null;
    public int     $newsId     = 0;
    public string  $title      = '';
    public string  $excerpt    = '';
    public string  $body       = '';
    public ?int    $authorId   = null;
    public ?string $createdAt  = null;
    public ?string $authorName = null;

    public function summary(): string
    {
        $title = trim($this->title);
        if ($title !== '') {
            return $title;
        }
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($this->body)) ?? '');
        if ($plain === '') {
            return 'Empty snapshot';
        }
        return function_exists('mb_substr') ? mb_substr($plain, 0, 72) : substr($plain, 0, 72);
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->title . "\n" . $this->excerpt . "\n" . $this->body);
    }
}
