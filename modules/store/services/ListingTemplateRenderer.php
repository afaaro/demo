<?php
declare(strict_types=1);

namespace Module\Store\Services;

/**
 * Simple {{placeholder}} substitution for listing title/description templates.
 */
final class ListingTemplateRenderer
{
    /**
     * @param array<string, scalar|null> $vars
     */
    public function render(string $template, array $vars): string
    {
        $map = [];
        foreach ($vars as $key => $value) {
            $map['{{' . $key . '}}'] = (string) ($value ?? '');
        }

        return strtr($template, $map);
    }

    /**
     * @return array{title: string, description: string}
     */
    public function apply(
        ?ListingTemplate $template,
        string $fallbackTitle,
        string $fallbackDescription,
        array $vars,
    ): array {
        if ($template === null) {
            return [
                'title'       => $fallbackTitle,
                'description' => $fallbackDescription,
            ];
        }

        $vars = array_merge([
            'title'         => $fallbackTitle,
            'description'   => $fallbackDescription,
            'product_name'  => $fallbackTitle,
            'variant_name'  => '',
            'sku'           => '',
            'price'         => '',
            'currency'      => '',
            'barcode'       => '',
            'image_url'     => '',
        ], $vars);

        $title = trim($this->render($template->titleTemplate, $vars));
        $desc  = trim($this->render($template->descriptionTemplate, $vars));

        return [
            'title'       => $title !== '' ? $title : $fallbackTitle,
            'description' => $desc !== '' ? $desc : $fallbackDescription,
        ];
    }
}
