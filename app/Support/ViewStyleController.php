<?php

declare(strict_types=1);

namespace MeldeVerkehr\Support;

use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;

final class ViewStyleController
{
    public function __construct(private readonly string $viewsPath)
    {
    }

    public function style(Request $request): Response
    {
        $template = trim((string) $request->query('view', ''));

        try {
            $css = (new ViewStyleCompiler($this->viewsPath))->css($template);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return Response::binary(
                '/* stylesheet not found */',
                'text/css; charset=utf-8',
                404,
                ['Cache-Control' => 'no-store']
            );
        }

        return Response::binary(
            $css === '' ? '/* no view-specific styles */' : $css,
            'text/css; charset=utf-8',
            200,
            [
                'Cache-Control' => 'public, max-age=3600',
                'ETag' => '"' . hash('sha256', $css) . '"',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
