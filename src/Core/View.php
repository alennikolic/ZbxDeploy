<?php

declare(strict_types=1);

namespace ZbxDeploy\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

final class View
{
    private static ?Environment $twig = null;

    public static function twig(): Environment
    {
        if (self::$twig instanceof Environment) {
            return self::$twig;
        }

        $loader = new FilesystemLoader(Config::appRoot() . '/templates');
        $twig = new Environment($loader, [
            'debug' => Config::isDebug(),
            'cache' => Config::isDebug() ? false : Config::dataDir() . '/tmp/twig',
            'strict_variables' => false,
        ]);

        $twig->addFilter(new TwigFilter('ago', static function (?string $timestamp): string {
            if ($timestamp === null || $timestamp === '') {
                return 'never';
            }
            $diff = time() - strtotime($timestamp);
            if ($diff < 60) {
                return $diff . 's ago';
            }
            if ($diff < 3600) {
                return intdiv($diff, 60) . 'm ago';
            }
            if ($diff < 86400) {
                return intdiv($diff, 3600) . 'h ago';
            }

            return intdiv($diff, 86400) . 'd ago';
        }));

        self::$twig = $twig;

        return self::$twig;
    }

    /** @param array<string, mixed> $context */
    public static function render(string $template, array $context = []): string
    {
        $context += [
            'csrf_token'  => Csrf::token(),
            'current_user' => Auth::check() ? Auth::user() : null,
            'app_name'    => 'ZbxDeploy',
            'flash'       => Flash::pull(),
        ];

        return self::twig()->render($template, $context);
    }

    /** @param array<string, mixed> $context */
    public static function send(string $template, array $context = []): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo self::render($template, $context);
    }

    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }
}
