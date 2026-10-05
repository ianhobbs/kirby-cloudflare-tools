<template>
  <div class="cf-overview">
    <license-notice :license="status.license" @changed="$emit('changed')" />

    <k-section label="Zone">
      <k-box v-if="status.zoneError" theme="negative" icon="alert" :text="status.zoneError" />
      <k-stats v-else :reports="zoneReports" size="large" />
    </k-section>

    <k-section label="Quick actions">
      <div class="cf-card">
        <div class="cf-row">
          <div>
            <strong>Purge everything</strong>
            <p class="cf-muted">
              Clears every file Cloudflare has cached for this site (and Kirby's
              page cache first). Visitors get fresh copies from your server.
            </p>
          </div>
          <k-button
            :disabled="!can.purge || busy"
            icon="refresh"
            variant="filled"
            theme="notice"
            text="Purge everything"
            @click="purgeEverything"
          />
        </div>

        <div v-if="settings" class="cf-row">
          <div>
            <strong>Development Mode</strong>
            <p class="cf-muted">
              Bypasses the cache for three hours while you work on the site.
              <template v-if="settings.developmentMode && settings.developmentLeft">
                {{ duration(settings.developmentLeft) }} left.
              </template>
            </p>
          </div>
          <k-toggle-input
            :key="'dev' + renders"
            :value="settings.developmentMode"
            :disabled="!can.settings || busy"
            :text="['Off', 'On']"
            @input="toggleDevelopmentMode"
          />
        </div>

        <div v-if="settings" class="cf-row">
          <div>
            <strong>I'm Under Attack</strong>
            <p class="cf-muted">
              Shows every visitor a short check before the site loads. Use it
              only during an attack; turning it off restores the previous level.
            </p>
          </div>
          <k-toggle-input
            :key="'attack' + renders"
            :value="settings.securityLevel === 'under_attack'"
            :disabled="!can.security || busy"
            :text="['Off', 'On']"
            @input="toggleUnderAttack"
          />
        </div>

        <div v-if="status.zone" class="cf-row">
          <div>
            <strong>{{ status.zone.paused ? 'Resume Cloudflare' : 'Pause Cloudflare' }}</strong>
            <p class="cf-muted">
              Pausing sends traffic straight to your server: no cache, no
              firewall, no protection. DNS keeps working.
            </p>
          </div>
          <k-button
            :disabled="!can.settings || busy"
            :theme="status.zone.paused ? 'positive' : 'negative'"
            :icon="status.zone.paused ? 'play' : 'shut-down'"
            variant="filled"
            :text="status.zone.paused ? 'Resume' : 'Pause'"
            @click="togglePause"
          />
        </div>
      </div>
      <p v-if="status.settingsError" class="cf-muted">
        Zone settings unavailable: {{ status.settingsError }}
      </p>
    </k-section>

    <analytics-panel v-if="can.analytics" />

    <k-section v-if="status.zone && status.zone.nameServers.length" label="Cloudflare name servers">
      <div class="cf-card cf-mono">
        <div v-for="server in status.zone.nameServers" :key="server">{{ server }}</div>
      </div>
    </k-section>
  </div>
</template>

<script>
import api from '../../api.js';
import AnalyticsPanel from '../AnalyticsPanel.vue';
import LicenseNotice from '../LicenseNotice.vue';

const LEVELS = {
  essentially_off: 'Essentially off',
  low: 'Low',
  medium: 'Medium',
  high: 'High',
  under_attack: "I'm Under Attack!",
};

const SSL = {
  off: 'Off',
  flexible: 'Flexible',
  full: 'Full',
  strict: 'Full (strict)',
};

export default {
  components: { AnalyticsPanel, LicenseNotice },
  mixins: [api],
  props: {
    status: { type: Object, required: true },
  },
  emits: ['changed'],
  data() {
    // re-rendering the toggles puts them back when an action is cancelled
    // or fails: the checkbox flips in the DOM before the server answers
    return { busy: false, renders: 0 };
  },
  computed: {
    can() {
      return this.status.permissions ?? {};
    },
    settings() {
      return this.status.settings;
    },
    zoneReports() {
      const zone = this.status.zone ?? {};
      const settings = this.settings ?? {};
      const active = zone.status === 'active' && !zone.paused;

      const reports = [
        {
          label: 'Domain',
          value: zone.name ?? '–',
          info: zone.paused ? 'Paused' : zone.status,
          theme: active ? 'positive' : 'notice',
          icon: 'globe',
        },
        {
          label: 'Plan',
          value: zone.plan ?? '–',
          info: zone.account,
          icon: 'cloud',
        },
      ];

      if (this.settings) {
        reports.push(
          {
            label: 'Security level',
            value: LEVELS[settings.securityLevel] ?? settings.securityLevel,
            theme: settings.securityLevel === 'under_attack' ? 'negative' : null,
            icon: 'shield',
          },
          {
            label: 'SSL/TLS',
            value: SSL[settings.ssl] ?? settings.ssl,
            info: settings.alwaysUseHttps ? 'Always HTTPS' : 'HTTP allowed',
            theme: settings.ssl === 'off' || settings.ssl === 'flexible' ? 'notice' : 'positive',
            icon: 'lock',
          }
        );
      }

      return reports;
    },
  },
  methods: {
    async run(action, success) {
      this.busy = true;

      try {
        const response = await action();
        this.$panel.notification.success(success ?? response?.message ?? 'Done');
        this.$emit('changed');
      } catch (error) {
        this.notifyError(error);
      } finally {
        this.busy = false;
        this.renders++;
      }
    },
    async purgeEverything() {
      const ok = await this.confirm(
        'Purge every file Cloudflare has cached for this site? Visitors will be served fresh copies, and your server will see more traffic for a while.',
        { button: 'Purge everything', icon: 'refresh' }
      );

      if (ok) await this.run(() => this.cf('post', 'purge', { type: 'everything' }));
    },
    toggleDevelopmentMode(on) {
      this.run(
        () => this.cf('patch', 'settings/cache', { changes: { development_mode: on } }),
        on ? 'Development Mode is on for three hours' : 'Development Mode is off'
      );
    },
    async toggleUnderAttack(on) {
      if (on) {
        const ok = await this.confirm(
          "Turn on I'm Under Attack mode? Every visitor gets a browser check before the site loads.",
          { button: 'Turn on', theme: 'negative', icon: 'shield' }
        );

        if (!ok) return this.renders++;
      }

      this.run(() => this.cf('post', 'under-attack', { enabled: on }));
    },
    async togglePause() {
      const paused = !this.status.zone.paused;

      if (paused) {
        const ok = await this.confirm(
          'Pause Cloudflare for this site? Traffic goes straight to your server, without caching or protection, until you resume.',
          { button: 'Pause Cloudflare', theme: 'negative', icon: 'shut-down' }
        );

        if (!ok) return;
      }

      this.run(
        () => this.cf('post', 'zone/pause', { paused }),
        paused ? 'Cloudflare is paused' : 'Cloudflare is active again'
      );
    },
  },
};
</script>
