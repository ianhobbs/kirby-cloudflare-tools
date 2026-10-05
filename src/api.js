/**
 * Shared helpers for the tabs: API calls under cloudflare-tools/, and
 * confirm dialogs. Errors surface as Panel notifications.
 */
export const base = 'cloudflare-tools/';

export default {
  methods: {
    async cf(method, path, data = {}) {
      return this.$api[method](base + path, data);
    },

    notifyError(error) {
      this.$panel.notification.error(error?.message ?? String(error));
    },

    /** Opens a confirm dialog; resolves true when the user submits */
    confirm(text, { button = 'OK', theme = 'notice', icon = 'alert' } = {}) {
      return new Promise((resolve) => {
        let answered = false;

        this.$panel.dialog.open({
          component: 'k-text-dialog',
          props: {
            text,
            submitButton: { text: button, theme, icon },
          },
          on: {
            submit: () => {
              answered = true;
              this.$panel.dialog.close();
              resolve(true);
            },
            cancel: () => resolve(false),
            close: () => answered || resolve(false),
          },
        });
      });
    },

    /** Opens a form dialog; resolves with the values, or null when cancelled */
    form(fields, value = {}, { button = 'Save', icon = 'check' } = {}) {
      return new Promise((resolve) => {
        let answered = false;

        this.$panel.dialog.open({
          component: 'k-form-dialog',
          props: {
            fields,
            value,
            submitButton: { text: button, icon, theme: 'positive' },
          },
          on: {
            submit: (values) => {
              answered = true;
              this.$panel.dialog.close();
              resolve(values);
            },
            cancel: () => resolve(null),
            close: () => answered || resolve(null),
          },
        });
      });
    },

    number(value) {
      return (value ?? 0).toLocaleString();
    },

    duration(seconds) {
      const minutes = Math.round((seconds ?? 0) / 60);

      if (minutes < 60) return `${minutes} min`;

      return `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
    },
  },
};
