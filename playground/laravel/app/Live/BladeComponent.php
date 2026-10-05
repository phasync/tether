<?php

namespace App\Live;

use Illuminate\Support\Str;
use Tether\Component;

/**
 * A component whose render() is a Blade view: App\Live\TodoList renders resources/views/live/todo-list.blade.php.
 * The view gets the component's properties as variables, the component as
 * $component, and $child to place children: {!! $child(TodoItem::class, ['text' => $text], key: (string) $id) !!}.
 * The view has one root element, and its output is raw HTML: escape with {{ }}.
 */
abstract class BladeComponent extends Component
{
    public function render(): string
    {
        return view('live.' . Str::kebab(class_basename($this)), (fn () => get_object_vars($this))->call($this) + ['component' => $this, 'child' => $this->child(...)])->render();
    }
}
