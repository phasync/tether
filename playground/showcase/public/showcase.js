// The showcase's own scripts. Tether's client is an inline module in the head, and this deferred script runs after it.

// region invoke
// A plain script calls a server method and gets the result back
document.addEventListener('click', async (event) => {
  const button = event.target.closest('[data-invoke]');
  if (!button) return;
  const out = document.getElementById('invoke-result');
  try {
    out.textContent = JSON.stringify(await Tether.invoke(button, 'square', Number(button.dataset.invoke)));
  } catch (error) {
    out.textContent = `${error.name}: ${error.message}`;
  }
});
// endregion

// region widget
// A stand-in for a third-party widget: it owns the DOM inside the element it is given
class TinyBars {
  constructor(el, values, onPick) {
    this.el = el;
    this.click = (event) => {
      const bar = event.target.closest('.bar');
      if (bar) onPick([...el.children].indexOf(bar));
    };
    el.addEventListener('click', this.click);
    this.set(values);
  }

  set(values) {
    const max = Math.max(...values);
    this.el.replaceChildren(...values.map((value) => {
      const bar = document.createElement('div');
      bar.className = 'bar';
      bar.style.height = `${(value / max) * 100}%`;
      bar.textContent = value;
      return bar;
    }));
  }

  flash(index) {
    const bar = this.el.children[index];
    bar.classList.add('flash');
    setTimeout(() => bar.classList.remove('flash'), 600);
  }

  destroy() {
    this.el.removeEventListener('click', this.click);
    this.el.replaceChildren();
  }
}
// endregion

// region hook
const hooklog = (line) => {
  const out = document.getElementById('hooklog');
  if (out) out.textContent += `${line}\n`;
};
const values = (el) => JSON.parse(el.dataset.values);

Tether.hook('Bars', {
  mounted() {
    hooklog('mounted');
    this.bars = new TinyBars(this.el.querySelector('.bars'), values(this.el), (index) => this.invoke('picked', index));
  },
  updated() {
    hooklog('updated');
    this.bars.set(values(this.el));
  },
  destroyed() {
    hooklog('destroyed');
    this.bars.destroy();
  },
  // $this->browser()->hook('Bars')->flash($index)
  flash(index) {
    this.bars.flash(index);
  },
});
// endregion
