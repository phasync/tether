<?php

namespace Showcase;

use Tether\Component;

/**
 * One card of the page: a title, the live demo, and the source that makes it work, read from
 * the real files: the component's methods listed in SHOW, and the regions of public/showcase.js in JS.
 */
abstract class Section extends Component
{
    public const ID = '';

    public const TITLE = '';

    protected const BLURB = '';

    /** @var list<string> methods of the component */
    protected const SHOW = [];

    /** @var list<string> `// region name` blocks of public/showcase.js */
    protected const JS = [];

    /** @var array<class-string, string> */
    private static array $sources = [];

    abstract protected function demo(): string;

    final public function render(): string
    {
        $id     = static::ID;
        $title  = $this->e(static::TITLE);
        $blurb  = $this->e(static::BLURB);
        $source = self::$sources[static::class] ??= $this->e($this->source());

        return <<<HTML
            <section class="card" id="{$id}">
              <header><h2>{$title}</h2><p>{$blurb}</p></header>
              <div class="body">
                <div class="demo">{$this->demo()}</div>
                <pre class="source" tabindex="0"><code>{$source}</code></pre>
              </div>
            </section>
            HTML;
    }

    private function source(): string
    {
        $blocks = [];
        foreach (static::SHOW as $name) {
            $method   = new \ReflectionMethod(static::class, $name);
            $file     = \file($method->getFileName(), \FILE_IGNORE_NEW_LINES);
            $start    = $method->getStartLine() - 1;
            while (\str_starts_with(\trim($file[$start - 1]), '#[')) {
                --$start; // reflection starts a method at its signature, not at its attributes
            }
            $blocks[] = $this->dedent(\array_slice($file, $start, $method->getEndLine() - $start));
        }
        if (static::JS) {
            $js = \file(__DIR__ . '/../public/showcase.js', \FILE_IGNORE_NEW_LINES);
            foreach (static::JS as $region) {
                $start    = \array_search("// region {$region}", $js, true) + 1;
                $end      = \array_search('// endregion', \array_slice($js, $start, null, true), true);
                $blocks[] = "// public/showcase.js\n" . $this->dedent(\array_slice($js, $start, $end - $start));
            }
        }

        return \implode("\n\n", $blocks);
    }

    /** @param list<string> $lines */
    private function dedent(array $lines): string
    {
        $indent = \min(\array_map(static fn (string $l) => \strlen($l) - \strlen(\ltrim($l)), \array_filter($lines, static fn (string $l) => '' !== \trim($l))));

        return \implode("\n", \array_map(static fn (string $l) => \substr($l, $indent), $lines));
    }
}
