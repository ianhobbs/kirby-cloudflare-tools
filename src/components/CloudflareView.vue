<template>
  <k-panel-inside class="k-cloudflare-tools-view">
    <k-header>
      {{ label }}
      <template #buttons>
        <k-button-group>
          <k-button
            v-if="dashboardUrl"
            :link="dashboardUrl"
            target="_blank"
            icon="open"
            size="sm"
            variant="filled"
            text="Cloudflare dashboard"
          />
          <k-button
            :disabled="loading"
            icon="refresh"
            size="sm"
            variant="filled"
            title="Reload"
            @click="load"
          />
        </k-button-group>
      </template>
    </k-header>

    <k-tabs :tab="tab" :tabs="tabButtons" />

    <k-box v-if="error" theme="negative" icon="alert" :text="error" />

    <k-empty v-else-if="!status" icon="loader">Loading…</k-empty>

    <setup-notice v-else-if="!status.configured" :missing="status.missing" />

    <component
      :is="tabComponent"
      v-else
      :key="tab"
      :status="status"
      @changed="load"
    />
  </k-panel-inside>
</template>

<script>
import api from '../api.js';
import SetupNotice from './SetupNotice.vue';
import OverviewTab from './tabs/OverviewTab.vue';
import CacheTab from './tabs/CacheTab.vue';
import SecurityTab from './tabs/SecurityTab.vue';
import SslTab from './tabs/SslTab.vue';
import SpeedTab from './tabs/SpeedTab.vue';
import DnsTab from './tabs/DnsTab.vue';
import DiagnosticsTab from './tabs/DiagnosticsTab.vue';

const TABS = {
  overview: OverviewTab,
  cache: CacheTab,
  security: SecurityTab,
  ssl: SslTab,
  speed: SpeedTab,
  dns: DnsTab,
  diagnostics: DiagnosticsTab,
};

export default {
  components: { SetupNotice },
  mixins: [api],
  props: {
    label: { type: String, default: 'Cloudflare' },
    tab: { type: String, default: 'overview' },
    tabs: { type: Array, default: () => [] },
  },
  data() {
    return {
      status: null,
      error: null,
      loading: false,
    };
  },
  computed: {
    tabButtons() {
      return this.tabs.map((tab) => ({
        ...tab,
        link: '/cloudflare-tools/' + tab.name,
      }));
    },
    tabComponent() {
      return TABS[this.tab] ?? OverviewTab;
    },
    dashboardUrl() {
      const zone = this.status?.zone;

      if (!zone?.name) return null;

      return zone.accountId
        ? `https://dash.cloudflare.com/${zone.accountId}/${zone.name}`
        : 'https://dash.cloudflare.com/';
    },
  },
  created() {
    this.load();
  },
  methods: {
    async load() {
      this.loading = true;
      this.error = null;

      try {
        this.status = await this.cf('get', 'status');
      } catch (error) {
        this.error = error.message;
      } finally {
        this.loading = false;
      }
    },
  },
};
</script>

<style>
.k-cloudflare-tools-view .k-tabs {
  margin-bottom: var(--spacing-6);
}
.k-cloudflare-tools-view .k-section + .k-section {
  margin-top: var(--spacing-12);
}
.cf-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--spacing-2);
}
.cf-muted {
  color: var(--color-text-dimmed);
  font-size: var(--text-sm);
}
.cf-mono {
  font-family: var(--font-mono);
  font-size: var(--text-xs);
}
.cf-card {
  padding: var(--spacing-6);
  background: var(--item-color-back);
  border-radius: var(--rounded);
  box-shadow: var(--box-shadow-item);
}
.cf-card + .cf-card {
  margin-top: var(--spacing-3);
}
.cf-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--spacing-6);
}
.cf-row + .cf-row {
  margin-top: var(--spacing-6);
  padding-top: var(--spacing-6);
  border-top: 1px solid var(--color-border);
}
.cf-row p {
  margin-top: var(--spacing-1);
}
.cf-row > :first-child {
  flex: 1;
  min-width: 0;
}
@media (max-width: 40rem) {
  .cf-row {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
