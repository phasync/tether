// The Interop component's browser side: a stopwatch the browser runs by itself
Tether.hook('Stopwatch', {
  mounted() {
    this.start = performance.now();
    this.display = this.el.querySelector('#stopwatch');
    this.timer = setInterval(() => { this.display.textContent = `${this.elapsed()} ms since the tab went live`; }, 100);
    // Say hello to the component; it answers
    this.invoke('hello', navigator.userAgent).then((reply) => { window.lastReply = reply; });
  },
  destroyed() {
    clearInterval(this.timer);
  },
  // Called by the server: $this->browser()->hook('Stopwatch')->elapsed()
  elapsed() {
    return Math.round(performance.now() - this.start);
  },
  // Called by the server: a result that can not be JSON
  circular() {
    const loop = {};
    loop.self = loop;
    return loop;
  },
});
