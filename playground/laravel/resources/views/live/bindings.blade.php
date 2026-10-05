<section id="bindings">
  <div id="hover" class="box {{ $hovering ? 'on' : '' }}" tether-on-pointerenter="enter" tether-on-pointerleave="leave" tether-on-pointermove.throttle-50="move">
    {{ $hovering ? 'Pointer inside' : 'Hover here' }} <span id="pointer">{{ $pointer }}</span>
  </div>
  <p>
    <input id="focus" placeholder="Focus me" tether-on-focus="focus" tether-on-blur="blur">
    <span id="focus-state">{{ $focused ? 'focused' : 'blurred' }}</span>
  </p>
  <p>
    <input id="keys" placeholder="Press keys (Enter, Ctrl+K)" tether-on-keydown.key-enter="keydown" tether-on-keydown.ctrl.key-k.prevent="keydown">
    <span id="key">{{ $key }}</span>
  </p>
</section>
