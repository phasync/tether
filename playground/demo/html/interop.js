// Hooks of the /interop page: they record their lifecycle in window.life
window.life = [];
Tether.hook('Life', {
  mounted() { window.life.push('mounted'); },
  updated() { window.life.push('updated'); },
  destroyed() { window.life.push('destroyed'); },
});
// An asynchronous mounted(): the server's hook() call waits for it
Tether.hook('Slow', {
  ready: false,
  mounted() {
    return new Promise((done) => setTimeout(() => { this.ready = true; done(); }, 300));
  },
});
