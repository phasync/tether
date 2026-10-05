<?php

namespace Showcase;

use Tether\Bind;
use Tether\Event\FocusEventArgs;

final class SignUp extends Section
{
    public const ID    = 'form';
    public const TITLE = 'Focus, blur and validation';
    protected const BLURB = 'A field is checked when you leave it, and live after that. The ring follows server state, set by focusin and focusout. Enter in the tag field adds a tag, and never when it only commits an input method\'s composition.';
    protected const SHOW  = ['focused', 'blurred', 'errors', 'addTag', 'save', 'demo', 'field'];

    #[Bind]
    public string $name = '';

    #[Bind]
    public string $email = '';

    #[Bind]
    public string $plan = 'free';

    #[Bind]
    public bool $terms = false;

    #[Bind]
    public string $tag = '';

    /** @var list<string> */
    private array $tags = [];

    /** @var array<string, true> fields the user has left */
    private array $touched = [];

    private string $focus = '';

    private string $saved = '';

    public function focused(FocusEventArgs $e): void
    {
        $this->focus = $e->name;
    }

    public function blurred(FocusEventArgs $e): void
    {
        $this->focus             = '';
        $this->touched[$e->name] = true;
    }

    /** @return array<string, string> */
    private function errors(): array
    {
        return \array_filter([
            'name'  => \strlen(\trim($this->name)) < 2 ? 'At least two characters' : '',
            'email' => false === \filter_var($this->email, \FILTER_VALIDATE_EMAIL) ? 'Not an email address' : '',
            'terms' => $this->terms ? '' : 'Accept to continue',
        ]);
    }

    public function addTag(): void
    {
        if ('' !== \trim($this->tag)) {
            $this->tags[] = \trim($this->tag);
            $this->tag    = '';
        }
    }

    public function save(): void
    {
        $this->touched = ['name' => true, 'email' => true, 'terms' => true];
        $this->saved   = [] === $this->errors() ? "Saved {$this->name} <{$this->email}> on the {$this->plan} plan" : '';
    }

    protected function demo(): string
    {
        $name   = $this->field('name', 'Name', "<input name=\"name\" autocomplete=\"off\" {$this->bind('name')}>");
        $email  = $this->field('email', 'Email', "<input name=\"email\" type=\"email\" autocomplete=\"off\" {$this->bind('email')}>");
        $tags   = \implode('', \array_map(fn (string $t) => '<span class="tag">' . $this->e($t) . '</span>', $this->tags));
        $tag    = $this->field('tag', 'Tags: Enter adds one', "<input name=\"tag\" autocomplete=\"off\" {$this->bind('tag')} tether-keydown.key-enter=\"addTag\" tether-args-keydown=\"[]\"><span class=\"tags\" id=\"tags\">{$tags}</span>");
        $plans  = \implode('', \array_map(fn (string $p) => "<option" . ($this->plan === $p ? ' selected' : '') . ">{$p}</option>", ['free', 'pro', 'team']));
        $plan   = $this->field('plan', 'Plan', "<select name=\"plan\" {$this->bind('plan')}>{$plans}</select>");
        $terms  = $this->field('terms', '', "<span><input type=\"checkbox\" name=\"terms\" {$this->bind('terms')}> I accept the terms</span>");
        $saved  = '' === $this->saved ? '' : '<p class="ok" id="saved">' . $this->e($this->saved) . '</p>';

        return <<<HTML
            <form class="form" id="signup" tether-submit="save" tether-on-focusin="focused" tether-on-focusout="blurred" novalidate>
              {$name}{$email}{$tag}{$plan}{$terms}
              <button>Save</button>{$saved}
            </form>
            HTML;
    }

    private function field(string $name, string $label, string $input): string
    {
        $error = isset($this->touched[$name]) ? ($this->errors()[$name] ?? '') : '';
        $class = ($this->focus === $name ? ' focus' : '') . ('' === $error ? '' : ' invalid');
        $error = '' === $error ? '' : "<small class=\"error\">{$error}</small>";

        return "<label class=\"field{$class}\" id=\"field-{$name}\"><span>{$label}</span>{$input}{$error}</label>";
    }
}
