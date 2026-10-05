<template>
  <k-box v-if="visible" :theme="theme" icon="key" class="cf-license-notice">
    <div class="cf-row">
      <div>
        <strong>{{ heading }}</strong>
        <p>{{ license.message }}</p>
      </div>
      <k-button-group>
        <k-button
          v-if="license.shop && license.status !== 'unknown'"
          :link="license.shop"
          target="_blank"
          icon="cart"
          size="sm"
          variant="filled"
          text="Buy a licence"
        />
        <k-button
          v-if="license.manage"
          icon="key"
          size="sm"
          variant="filled"
          :theme="theme"
          :text="license.source ? 'Manage licence' : 'Enter licence key'"
          @click="open"
        />
      </k-button-group>
    </div>
  </k-box>
</template>

<script>
// Shown on the Overview tab only, and only while a public website has no
// working licence. It never blocks anything: see LICENSE, "honest signal".
export default {
  props: {
    license: { type: Object, default: null },
  },
  emits: ['changed'],
  computed: {
    visible() {
      return ['missing', 'inactive', 'unknown'].includes(this.license?.status);
    },
    theme() {
      return this.license.status === 'inactive' ? 'negative' : 'notice';
    },
    heading() {
      return {
        missing: 'Unlicensed website',
        inactive: 'Licence inactive',
        unknown: 'Licence not verified',
      }[this.license.status];
    },
  },
  methods: {
    open() {
      this.$panel.dialog.open('cloudflare-tools/license', {
        on: {
          success: (response) => {
            this.$panel.dialog.close();
            this.$panel.notification.success(response?.message ?? 'Saved');
            this.$emit('changed');
          },
        },
      });
    },
  },
};
</script>

<style>
.cf-license-notice {
  margin-bottom: var(--spacing-6);
}
.cf-license-notice p {
  margin-top: var(--spacing-1);
}
</style>
