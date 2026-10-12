<template>
  <div>
    <k-section label="Connection check">
      <template #options>
        <k-button
          :disabled="!can.purge || running"
          icon="check"
          size="xs"
          variant="filled"
          :text="running ? 'Checking…' : 'Run check'"
          @click="run"
        />
      </template>

      <div class="cf-card">
        <p v-if="!report" class="cf-muted">
          Checks the token is active, the Zone ID belongs to this site, and a
          purge really reaches Cloudflare's edge. It purges one small probe
          file only (the site's favicon unless the <code>probe</code> option
          says otherwise); nothing else in the cache is touched.
        </p>
        <ul v-else class="cf-report">
          <li
            v-for="(line, index) in report.lines"
            :key="index"
            :data-state="state(line)"
          >
            {{ line }}
          </li>
        </ul>
      </div>
    </k-section>

    <k-section label="From the command line">
      <div class="cf-card">
        <pre class="cf-mono">vendor/bin/kirby cloudflare-tools:check
vendor/bin/kirby cloudflare-tools:purge
vendor/bin/kirby cloudflare-tools:api GET 'zones/{zone}/settings'</pre>
        <p class="cf-muted">
          The same checks as above, a full purge (Kirby's page cache first),
          and any Cloudflare API call for trying out features this panel
          doesn't cover yet. The first two also work as Janitor buttons.
        </p>
      </div>
    </k-section>
  </div>
</template>

<script>
import api from '../../api.js';

export default {
  mixins: [api],
  props: {
    status: { type: Object, required: true },
  },
  data() {
    return { report: null, running: false };
  },
  computed: {
    can() {
      return this.status.permissions ?? {};
    },
  },
  methods: {
    async run() {
      this.running = true;

      try {
        this.report = await this.cf('post', 'diagnostics');
        this.report.ok
          ? this.$panel.notification.success('Cloudflare token and zone work')
          : this.$panel.notification.error('Cloudflare check failed, see the report');
      } catch (error) {
        this.notifyError(error);
      } finally {
        this.running = false;
      }
    },
    state(line) {
      if (line.startsWith('✓')) return 'pass';
      if (line.startsWith('✗')) return 'fail';
      return 'note';
    },
  },
};
</script>

<style>
.cf-report {
  font-family: var(--font-mono);
  font-size: var(--text-sm);
  line-height: 1.6;
}
.cf-report [data-state='pass'] {
  color: var(--color-positive);
}
.cf-report [data-state='fail'] {
  color: var(--color-negative);
}
.cf-report [data-state='note'] {
  color: var(--color-text-dimmed);
}
</style>
