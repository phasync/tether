<main id="chat">
  <h1>#{{ $room }}</h1>
  <ul id="lines">
    @foreach ($lines as $line)
      <li>{{ $line }}</li>
    @endforeach
  </ul>
  <input id="text" value="{{ $draft }}" placeholder="Say something, as {{ $user }}" tether-input="type" tether-keydown.key-enter="say">
</main>
