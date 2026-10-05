@use('App\Live\Bindings')
@use('App\Live\Counter')
@use('App\Live\TodoList')
<main>
  <h1>{{ $heading }}</h1>
  {!! $child(Counter::class) !!}
  {!! $child(TodoList::class) !!}
  {!! $child(Bindings::class) !!}
</main>
