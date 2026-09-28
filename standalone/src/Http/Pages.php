<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Http;

/** Minimal static error pages. No user input is echoed. */
final class Pages
{
    public static function forbidden(): string
    {
        return self::page(
            'Kein Zugang',
            'Ihr Konto ist für dieses Webmail nicht freigeschaltet. Wenden Sie sich an Ihre IT, wenn Sie Zugang benötigen.',
            '/oidc/logout',
            'Abmelden',
        );
    }

    public static function unavailable(): string
    {
        return self::page(
            'Anmeldung nicht erreichbar',
            'Der Anmeldedienst antwortet gerade nicht. Bitte versuchen Sie es in einigen Minuten erneut.',
            '/oidc/login',
            'Erneut versuchen',
        );
    }

    public static function expired(): string
    {
        return self::page(
            'Anmeldung abgelaufen',
            'Die Anmeldung ist abgelaufen oder wurde in einem anderen Fenster fortgesetzt.',
            '/oidc/login',
            'Erneut anmelden',
        );
    }

    /** All arguments are compile-time constants from this class; still escaped in case that ever changes. */
    private static function page(string $title, string $message, string $actionHref, string $actionLabel): string
    {
        $title = \htmlspecialchars($title, \ENT_QUOTES);
        $message = \htmlspecialchars($message, \ENT_QUOTES);
        $actionHref = \htmlspecialchars($actionHref, \ENT_QUOTES);
        $actionLabel = \htmlspecialchars($actionLabel, \ENT_QUOTES);

        return <<<HTML
        <!doctype html>
        <html lang="de">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>X2Mail &ndash; {$title}</title>
        <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: #f2f3f5;
            color: #1c1c1e;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .card {
            width: 100%;
            max-width: 360px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
            padding: 32px 24px;
            text-align: center;
            box-sizing: border-box;
        }
        .card img { display: block; margin: 0 auto 16px; }
        .card h1 { font-size: 1.25rem; margin: 0 0 12px; }
        .card p { font-size: 0.95rem; line-height: 1.5; margin: 0 0 24px; color: #4a4a4f; }
        .card a.button {
            display: inline-block;
            padding: 10px 20px;
            border-radius: 8px;
            background: #0a66c2;
            color: #ffffff;
            text-decoration: none;
            font-weight: 600;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #1c1c1e; color: #f2f2f7; }
            .card { background: #2c2c2e; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.5); }
            .card p { color: #c7c7cc; }
        }
        </style>
        </head>
        <body>
        <div class="card">
        <img src="/x2mail/v/current/static/logo-512.png" alt="X2Mail" width="64" height="64">
        <h1>{$title}</h1>
        <p>{$message}</p>
        <a class="button" href="{$actionHref}">{$actionLabel}</a>
        </div>
        </body>
        </html>
        HTML;
    }
}
