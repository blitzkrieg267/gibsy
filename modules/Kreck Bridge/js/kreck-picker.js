/*!
 * KRECK picker — a searchable dropdown that progressively enhances a native <select>.
 *
 *   <select name="x" data-kpicker>                      single, opens the full list on click, type to filter
 *   <select name="x[]" multiple data-kpicker>           multi-select with chips
 *   <select ... data-kpicker data-kpicker-create>       also lets the user add a value that is not in the list
 *
 * Optional per-<option> attributes:  data-meta="secondary text"   data-search="extra search words"
 * Optional per-<select> attributes:  data-placeholder="…"  data-kp-message="…" (required message)
 *
 * The native <select> stays in the DOM and remains the submitted field, so server code,
 * `getElementById(...).value` and `change` listeners keep working, and the page still works with no JS.
 * Dynamic content: call window.kpickerInit(root) (a MutationObserver also enhances nodes added later).
 */
(function () {
  'use strict';
  if (window.KreckPicker) { return; }

  var uid = 0;
  var SEARCH_MIN = 8;      // show the search box once there are at least this many options
  var MAX_ROWS = 300;      // rows rendered at once; the user narrows the rest by typing

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text != null) { e.textContent = text; }
    return e;
  }
  function norm(s) { return String(s == null ? '' : s).toLowerCase(); }

  function readOptions(sel) {
    var out = [];
    function push(o, group) {
      var meta = o.getAttribute('data-meta') || '';
      out.push({
        value: o.value,
        label: o.textContent.trim(),
        meta: meta,
        search: norm(o.textContent + ' ' + meta + ' ' + (o.getAttribute('data-search') || '')),
        group: group || '',
        disabled: o.disabled,
        el: o
      });
    }
    Array.prototype.forEach.call(sel.children, function (c) {
      if (c.tagName === 'OPTGROUP') {
        Array.prototype.forEach.call(c.children, function (o) { push(o, c.label); });
      } else if (c.tagName === 'OPTION') {
        push(c, '');
      }
    });
    return out;
  }

  // Append `text` to `node`, wrapping the first occurrence of any search term in <b>.
  function highlight(node, text, terms) {
    var lower = text.toLowerCase(), at = -1, len = 0;
    terms.forEach(function (t) {
      var i = lower.indexOf(t);
      if (i >= 0 && (at < 0 || i < at)) { at = i; len = t.length; }
    });
    if (at < 0) { node.appendChild(document.createTextNode(text)); return; }
    node.appendChild(document.createTextNode(text.slice(0, at)));
    node.appendChild(el('b', null, text.slice(at, at + len)));
    node.appendChild(document.createTextNode(text.slice(at + len)));
  }

  function enhance(sel) {
    if (sel.__kp || sel.tagName !== 'SELECT') { return; }
    var multi = sel.multiple;
    var creatable = sel.hasAttribute('data-kpicker-create');
    var placeholder = sel.getAttribute('data-placeholder') || (multi ? 'Select one or more…' : 'Select…');
    var requiredMsg = sel.getAttribute('data-kp-message') || 'Please choose an option.';
    var id = 'kp' + (++uid);

    var opts = readOptions(sel);
    var query = '';
    var view = [];
    var hi = -1;
    var isOpen = false;
    var showSearch = multi || opts.length >= SEARCH_MIN || creatable;

    var wrap = el('div', 'kp' + (multi ? ' kp-is-multi' : ''));
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    sel.classList.add('kp-native');
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');
    if (sel.required) { sel.required = false; sel.setAttribute('data-kp-required', '1'); }

    // ---- control -----------------------------------------------------------
    var ctl, valueEl = null, chipsEl = null, inline = null;
    if (multi) {
      ctl = el('div', 'kp-ctl kp-multi');
      chipsEl = el('span', 'kp-chips');
      inline = el('input', 'kp-inline');
      inline.type = 'text';
      inline.autocomplete = 'off';
      inline.setAttribute('role', 'combobox');
      inline.setAttribute('aria-autocomplete', 'list');
      inline.setAttribute('aria-controls', id + '-list');
      inline.setAttribute('aria-expanded', 'false');
      ctl.appendChild(chipsEl);
      ctl.appendChild(inline);
    } else {
      ctl = el('button', 'kp-ctl');
      ctl.type = 'button';
      ctl.setAttribute('role', 'combobox');
      ctl.setAttribute('aria-haspopup', 'listbox');
      ctl.setAttribute('aria-controls', id + '-list');
      ctl.setAttribute('aria-expanded', 'false');
      valueEl = el('span', 'kp-val');
      ctl.appendChild(valueEl);
      ctl.appendChild(el('span', 'kp-caret'));
    }
    wrap.appendChild(ctl);

    // ---- popup -------------------------------------------------------------
    var pop = el('div', 'kp-pop');
    pop.hidden = true;
    var search = null;
    if (!multi && showSearch) {
      search = el('input', 'kp-search');
      search.type = 'text';
      search.autocomplete = 'off';
      search.placeholder = 'Type to search…';
      search.setAttribute('aria-controls', id + '-list');
      pop.appendChild(search);
    }
    var list = el('ul', 'kp-list');
    list.id = id + '-list';
    list.setAttribute('role', 'listbox');
    if (multi) { list.setAttribute('aria-multiselectable', 'true'); }
    pop.appendChild(list);
    wrap.appendChild(pop);

    // The element that receives typing / arrow keys.
    var typer = multi ? inline : (search || ctl);
    var focusable = multi ? inline : ctl;

    // Clicking the field's <label> should land on our control, not the hidden select.
    if (sel.id) {
      Array.prototype.forEach.call(document.querySelectorAll('label[for="' + sel.id + '"]'), function (lab) {
        lab.addEventListener('click', function (e) { e.preventDefault(); focusable.focus(); if (!isOpen) { openList(); } });
      });
    }

    // ---- state helpers -----------------------------------------------------
    function selectedValues() {
      var out = [];
      Array.prototype.forEach.call(sel.options, function (o) { if (o.selected && o.value !== '') { out.push(o.value); } });
      return out;
    }
    function isSelected(item) {
      if (multi) { return item.el.selected; }
      return sel.value === item.value && item.value !== '';
    }

    function syncDisplay() {
      opts = readOptions(sel);
      if (multi) {
        while (chipsEl.firstChild) { chipsEl.removeChild(chipsEl.firstChild); }
        var any = false;
        opts.forEach(function (o) {
          if (!o.el.selected || o.value === '') { return; }
          any = true;
          var chip = el('span', 'kp-chip');
          chip.appendChild(document.createTextNode(o.label));
          var x = el('button', 'kp-x', '×');
          x.type = 'button';
          x.setAttribute('aria-label', 'Remove ' + o.label);
          x.addEventListener('click', function (e) {
            e.stopPropagation();
            o.el.selected = false;
            fireChange();
            syncDisplay();
            renderList();
          });
          chip.appendChild(x);
          chipsEl.appendChild(chip);
        });
        inline.placeholder = any ? '' : placeholder;
      } else {
        var cur = null;
        opts.forEach(function (o) { if (sel.value === o.value && o.value !== '') { cur = o; } });
        valueEl.textContent = cur ? cur.label : placeholder;
        valueEl.classList.toggle('kp-placeholder', !cur);
      }
      var invalid = ctl.getAttribute('aria-invalid') === 'true';
      if (invalid && (multi ? selectedValues().length : sel.value)) {
        ctl.removeAttribute('aria-invalid');
        wrap.classList.remove('kp-invalid');
        var m = wrap.querySelector('.kp-msg');
        if (m) { m.remove(); }
      }
      ctl.classList.toggle('kp-disabled', sel.disabled);
      if (multi) { inline.disabled = sel.disabled; } else { ctl.disabled = sel.disabled; }
    }

    function fireChange() {
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function buildView() {
      var terms = norm(query).split(/\s+/).filter(Boolean);
      var rows = [];
      if (!multi && !sel.getAttribute('data-kp-required') && !terms.length) {
        opts.forEach(function (o) { if (o.value === '' && !o.disabled && rows.length === 0) { rows.push({ item: o, none: true }); } });
      }
      var matched = 0, shown = 0, lastGroup = null;
      opts.forEach(function (o) {
        if (o.disabled || o.value === '') { return; }
        if (terms.length && !terms.every(function (t) { return o.search.indexOf(t) >= 0; })) { return; }
        matched++;
        if (shown >= MAX_ROWS) { return; }
        if (o.group && o.group !== lastGroup) { rows.push({ header: o.group }); lastGroup = o.group; }
        rows.push({ item: o, terms: terms });
        shown++;
      });
      var q = query.trim();
      if (creatable && q && !opts.some(function (o) { return norm(o.label) === norm(q); })) {
        rows.push({ create: q });
      }
      if (matched > shown) { rows.push({ more: matched - shown }); }
      if (!matched && !(creatable && q)) { rows.push({ empty: true }); }
      return rows;
    }

    function renderList() {
      view = buildView();
      while (list.firstChild) { list.removeChild(list.firstChild); }
      var firstSel = -1, idx = 0;
      view.forEach(function (r) {
        if (r.header) {
          var h = el('li', 'kp-group', r.header);
          h.setAttribute('role', 'presentation');
          list.appendChild(h);
          return;
        }
        if (r.empty) { list.appendChild(el('li', 'kp-empty', 'No matches.')); return; }
        if (r.more) { list.appendChild(el('li', 'kp-empty', r.more + ' more — keep typing to narrow.')); return; }
        var li = el('li', 'kp-opt');
        li.setAttribute('role', 'option');
        li.setAttribute('data-i', String(idx));
        if (r.create) {
          li.classList.add('kp-create');
          li.appendChild(document.createTextNode('Add “' + r.create + '”'));
        } else if (r.none) {
          li.classList.add('kp-none');
          li.appendChild(document.createTextNode(r.item.label || '— none —'));
        } else {
          var nm = el('span', 'kp-nm');
          highlight(nm, r.item.label, r.terms || []);
          li.appendChild(nm);
          if (r.item.meta) {
            var mt = el('span', 'kp-meta');
            highlight(mt, r.item.meta, r.terms || []);
            li.appendChild(mt);
          }
          if (isSelected(r.item)) {
            li.classList.add('kp-sel');
            li.setAttribute('aria-selected', 'true');
            if (firstSel < 0) { firstSel = idx; }
          }
        }
        r.rowIndex = idx;
        li.addEventListener('mousedown', function (e) { e.preventDefault(); });
        li.addEventListener('click', function () { choose(r); });
        list.appendChild(li);
        idx++;
      });
      var selectable = view.filter(function (r) { return r.item || r.create; });
      view = selectable;
      hi = firstSel >= 0 && !query ? firstSel : (selectable.length ? 0 : -1);
      paintHi(true);
    }

    function paintHi(scroll) {
      var items = list.querySelectorAll('li.kp-opt');
      Array.prototype.forEach.call(items, function (li, i) {
        li.classList.toggle('kp-hi', i === hi);
        if (i === hi) {
          typer.setAttribute('aria-activedescendant', id + '-o' + i);
          li.id = id + '-o' + i;
          if (scroll && li.scrollIntoView) { li.scrollIntoView({ block: 'nearest' }); }
        }
      });
    }

    function choose(r) {
      if (!r) { return; }
      var item = r.item;
      if (r.create) {
        var o = document.createElement('option');
        o.value = r.create;
        o.textContent = r.create;
        sel.appendChild(o);
        opts = readOptions(sel);
        item = { el: o, value: r.create };
      }
      if (multi) {
        item.el.selected = !item.el.selected || !!r.create;
        fireChange();
        syncDisplay();
        // Close after each pick so the list never sits over the buttons below; focus stays in the
        // field, so typing (or ArrowDown / a click) reopens it for the next value.
        closeList(false);
      } else {
        sel.value = item.value;
        if (item.el) { item.el.selected = true; }
        fireChange();
        syncDisplay();
        closeList(true);
      }
    }

    function openList(keepQuery) {
      if (isOpen || sel.disabled) { return; }
      isOpen = true;
      pop.hidden = false;
      ctl.setAttribute('aria-expanded', 'true');
      if (multi) { inline.setAttribute('aria-expanded', 'true'); }
      wrap.classList.add('kp-open');
      if (keepQuery === true) {
        query = multi ? inline.value : (search ? search.value : '');
      } else {
        query = '';
        if (search) { search.value = ''; }
        if (multi) { inline.value = ''; }
      }
      renderList();
      if (search) { search.focus(); }
    }

    function closeList(refocus) {
      if (!isOpen) { return; }
      isOpen = false;
      pop.hidden = true;
      ctl.setAttribute('aria-expanded', 'false');
      if (multi) { inline.setAttribute('aria-expanded', 'false'); inline.value = ''; }
      wrap.classList.remove('kp-open');
      query = '';
      if (refocus) { focusable.focus(); }
    }

    function onKey(e) {
      var k = e.key;
      if (!isOpen) {
        if (k === 'ArrowDown' || k === 'ArrowUp' || k === 'Enter' || k === ' ') {
          if (multi && k === ' ') { return; }
          e.preventDefault();
          openList();
        } else if (!multi && !search && k.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
          // Short lists have no search box: type-ahead jumps to the first matching option.
          var m = opts.filter(function (o) { return !o.disabled && o.value !== '' && norm(o.label).indexOf(norm(k)) === 0; })[0];
          if (m) { sel.value = m.value; fireChange(); syncDisplay(); }
        }
        return;
      }
      if (k === 'ArrowDown') { e.preventDefault(); if (view.length) { hi = Math.min(hi + 1, view.length - 1); paintHi(true); } }
      else if (k === 'ArrowUp') { e.preventDefault(); if (view.length) { hi = Math.max(hi - 1, 0); paintHi(true); } }
      else if (k === 'Home' && !typer.value) { e.preventDefault(); hi = 0; paintHi(true); }
      else if (k === 'End' && !typer.value) { e.preventDefault(); hi = view.length - 1; paintHi(true); }
      else if (k === 'Enter') { e.preventDefault(); if (hi >= 0) { choose(view[hi]); } }
      else if (k === ' ' && typer === ctl) { e.preventDefault(); if (hi >= 0) { choose(view[hi]); } }
      else if (k === 'Escape') { e.preventDefault(); closeList(true); }
      else if (k === 'Tab') { closeList(false); }
      else if (k === 'Backspace' && multi && !inline.value) {
        var picked = opts.filter(function (o) { return o.el.selected && o.value !== ''; });
        if (picked.length) { picked[picked.length - 1].el.selected = false; fireChange(); syncDisplay(); renderList(); }
      }
    }

    // ---- events ------------------------------------------------------------
    if (multi) {
      ctl.addEventListener('click', function () { inline.focus(); openList(); });
      inline.addEventListener('focus', function () { openList(); });
      inline.addEventListener('input', function () { if (!isOpen) { openList(true); } query = inline.value; renderList(); });
      inline.addEventListener('keydown', onKey);
    } else {
      ctl.addEventListener('click', function () { isOpen ? closeList(true) : openList(); });
      ctl.addEventListener('keydown', onKey);
      // A button fires its click on Space *keyup*; swallow it so the list we just opened is not toggled shut.
      ctl.addEventListener('keyup', function (e) { if (e.key === ' ') { e.preventDefault(); } });
      if (search) {
        search.addEventListener('input', function () { query = search.value; renderList(); });
        search.addEventListener('keydown', onKey);
      }
    }
    document.addEventListener('mousedown', function (e) {
      if (isOpen && !wrap.contains(e.target)) { closeList(false); }
    });
    sel.addEventListener('change', function () { syncDisplay(); if (isOpen) { renderList(); } });

    var form = sel.form;
    if (form && !form.__kpBound) {
      form.__kpBound = true;
      form.addEventListener('reset', function () {
        setTimeout(function () {
          Array.prototype.forEach.call(form.querySelectorAll('select.kp-native'), function (s) { if (s.__kp) { s.__kp.rebuild(); } });
        }, 0);
      });
      form.addEventListener('submit', function (e) {
        var bad = null;
        Array.prototype.forEach.call(form.querySelectorAll('select[data-kp-required]'), function (s) {
          var has = s.multiple ? Array.prototype.some.call(s.options, function (o) { return o.selected && o.value !== ''; }) : !!s.value;
          if (!has && s.__kp && !bad) { bad = s; }
        });
        if (bad) {
          e.preventDefault();
          e.stopImmediatePropagation();
          bad.__kp.invalid();
        }
      }, true);
    }

    sel.__kp = {
      rebuild: function () { syncDisplay(); if (isOpen) { renderList(); } },
      invalid: function () {
        wrap.classList.add('kp-invalid');
        ctl.setAttribute('aria-invalid', 'true');
        if (!wrap.querySelector('.kp-msg')) { wrap.appendChild(el('div', 'kp-msg', requiredMsg)); }
        focusable.focus();
        if (wrap.scrollIntoView) { wrap.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
      },
      open: openList,
      close: closeList
    };
    syncDisplay();
  }

  function init(root) {
    var scope = root && root.querySelectorAll ? root : document;
    Array.prototype.forEach.call(scope.querySelectorAll('select[data-kpicker]'), enhance);
    if (scope.matches && scope.matches('select[data-kpicker]')) { enhance(scope); }
  }

  window.KreckPicker = { enhance: enhance, init: init };
  window.kpickerInit = init;

  function boot() {
    init(document);
    if (window.MutationObserver) {
      new MutationObserver(function (muts) {
        muts.forEach(function (m) {
          Array.prototype.forEach.call(m.addedNodes, function (n) {
            if (n.nodeType === 1) { init(n); }
          });
        });
      }).observe(document.body, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
