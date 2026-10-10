/*
 * MOPS Alpine components.
 *
 * The CSP build cannot evaluate inline expressions, so every interactive piece
 * of the admin UI registers here and templates only reference component names:
 *   x-data="drawer"  x-data="dropdown"  x-data="richEditor"  ...
 */
document.addEventListener('alpine:init', () => {
  const Alpine = window.Alpine;

  Alpine.data('drawer', () => ({
    open: false,
    toggle() {
      this.open = !this.open;
    },
    close() {
      this.open = false;
    },
  }));

  Alpine.data('dropdown', () => ({
    open: false,
    toggle() {
      this.open = !this.open;
    },
    close() {
      this.open = false;
    },
  }));

  Alpine.data('modal', () => ({
    open: false,
    show() {
      this.open = true;
    },
    hide() {
      this.open = false;
    },
  }));

  // Ask before running a destructive action. The button always belongs to a
  // real <form>, so cancelling simply prevents the submit.
  Alpine.data('confirmButton', (message = 'Yakin melanjutkan?') => ({
    ask(event) {
      if (!window.confirm(message)) {
        event.preventDefault();
      }
    },
  }));

  Alpine.data('bulkSelect', () => ({
    all: false,
    ids: [],
    toggleAll() {
      this.ids = this.all
        ? Array.from(document.querySelectorAll('input[name="ids[]"]')).map((el) => el.value)
        : [];
      this.sync();
    },
    sync() {
      const boxes = document.querySelectorAll('input[name="ids[]"]');
      if (this.ids.length === 0) {
        this.all = false;
        boxes.forEach((el) => {
          el.checked = false;
        });
        return;
      }
      boxes.forEach((el) => {
        el.checked = this.ids.includes(el.value);
      });
      this.all = boxes.length > 0 && this.ids.length === boxes.length;
    },
    apply() {
      if (this.ids.length === 0) {
        window.alert('Pilih minimal satu item.');
        return;
      }
      if (window.confirm('Hapus ' + this.ids.length + ' item terpilih?')) {
        this.$refs.bulk_form.submit();
      }
    },
  }));

  // Upload previews: keeps a data URL for the chosen file so the form can show
  // it before the request is sent.
  Alpine.data('uploadPreview', () => ({
    preview: null,
    pick(event) {
      const file = event.target.files && event.target.files[0];
      if (!file) {
        this.preview = null;
        return;
      }
      const reader = new FileReader();
      reader.onload = () => {
        this.preview = reader.result;
      };
      reader.readAsDataURL(file);
    },
  }));

  // Media picker: loads /admin/media/library.json and writes the chosen path
  // into a hidden input + preview.
  Alpine.data('mediaPicker', (libraryUrl = '/admin/media/library.json') => ({
    open: false,
    loading: false,
    items: [],
    value: '',
    preview: '',
    error: '',
    init() {
      this.value = this.$el.dataset.value || '';
      this.preview = this.value ? this.pathToUrl(this.value) : '';
    },
    pathToUrl(path) {
      if (!path) return '';
      if (path.startsWith('http') || path.startsWith('/')) return path;
      return '/uploads/' + path;
    },
    async load() {
      if (this.items.length > 0) {
        this.open = true;
        return;
      }
      this.loading = true;
      this.error = '';
      try {
        const response = await fetch(libraryUrl, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const payload = await response.json();
        this.items = payload.items || [];
        this.open = true;
      } catch (error) {
        this.error = 'Gagal memuat media library: ' + error.message;
        this.open = true;
      } finally {
        this.loading = false;
      }
    },
    choose(item) {
      this.value = item.path;
      this.preview = item.url;
      this.open = false;
    },
    clear() {
      this.value = '';
      this.preview = '';
    },
  }));

  // Range slider + counter pairs (skill level, etc).
  Alpine.data('rangeField', (initial = 0) => ({
    value: Number(initial) || 0,
    set(event) {
      this.value = Number(event.target.value);
    },
  }));

  Alpine.data('charCounter', (initial = '') => ({
    count: String(initial || '').length,
    update(event) {
      this.count = event.target.value.length;
    },
  }));

  // Repeater rows for social links / stats.
  Alpine.data('repeater', (rows = []) => ({
    rows: rows.length > 0 ? rows : [{ label: '', url: '', icon: '' }],
    add() {
      this.rows.push({ label: '', url: '', icon: '' });
    },
    remove(index) {
      this.rows.splice(index, 1);
      if (this.rows.length === 0) this.add();
    },
  }));

  // Minimal rich-text toolbar: wraps the selection in tags and can insert a
  // media URL into the textarea. No external editor dependency.
  Alpine.data('richEditor', () => ({
    wrap(open, close) {
      const area = this.$refs.textarea;
      if (!area) return;
      const start = area.selectionStart;
      const end = area.selectionEnd;
      const selected = area.value.slice(start, end);
      const insert = open + selected + (close || '');
      area.value = area.value.slice(0, start) + insert + area.value.slice(end);
      area.focus();
      area.selectionStart = area.selectionEnd = start + insert.length;
    },
    insertImage(path) {
      const area = this.$refs.textarea;
      if (!area || !path) return;
      const url = path.startsWith('http') || path.startsWith('/') ? path : '/uploads/' + path;
      const snippet = '\n<figure><img src="' + url + '" alt=""><figcaption></figcaption></figure>\n';
      const start = area.selectionStart;
      area.value = area.value.slice(0, start) + snippet + area.value.slice(start);
      area.focus();
    },
  }));

});
