@use('App\Live\TodoItem')
<section id="todos">
  <ul>
    @foreach ($items as $id => $text)
      {!! $child(TodoItem::class, ['text' => $text, 'onRemove' => fn () => $component->remove($id)], key: (string) $id) !!}
    @endforeach
  </ul>
  <input id="draft" value="{{ $draft }}" placeholder="New item, then Enter" tether-input="type" tether-keydown.key-enter="add">
</section>
