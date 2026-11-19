<?php

declare(strict_types=1);

namespace Machma\Forms\Admin;

/** Server-rendered PHP templates. Every value is escaped with e(). */
final class View
{
    public function __construct(private readonly string $dir)
    {
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        $content = $this->partial($template, $vars);
        return $this->partial('admin/layout', $vars + ['content' => $content]);
    }

    /** @param array<string, mixed> $vars */
    public function partial(string $template, array $vars = []): string
    {
        $file = $this->dir . '/' . $template . '.php';
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return (static function () use ($file, $vars, $e): string {
            extract($vars, EXTR_SKIP);
            ob_start();
            require $file;
            return (string) ob_get_clean();
        })();
    }
}
