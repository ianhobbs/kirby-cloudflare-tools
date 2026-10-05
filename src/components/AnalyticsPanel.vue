<template>
  <k-section label="Traffic">
    <template #options>
      <k-button-group>
        <k-button
          v-for="option in ranges"
          :key="option"
          :current="days === option"
          :text="option === 1 ? 'Today' : option + ' days'"
          size="xs"
          variant="filled"
          @click="select(option)"
        />
      </k-button-group>
    </template>

    <k-box v-if="error" theme="negative" icon="alert" :text="error" />
    <k-empty v-else-if="!traffic" icon="loader">Loading traffic…</k-empty>
    <template v-else>
      <k-stats :reports="reports" size="small" />

      <div class="cf-charts">
        <div v-for="chart in charts" :key="chart.key" class="cf-card">
          <p class="cf-chart-label">{{ chart.label }}</p>
          <div class="cf-chart" role="img" :aria-label="chart.label">
            <span
              v-for="row in traffic.series"
              :key="row.date"
              class="cf-chart-bar"
              :style="{ height: height(row[chart.key], chart.key) + '%' }"
              :title="row.date + ': ' + chart.format(row[chart.key])"
            />
          </div>
          <p class="cf-muted cf-chart-range">
            <span>{{ first }}</span><span>{{ last }}</span>
          </p>
        </div>
      </div>

      <div v-if="traffic.topCountries.length" class="cf-card cf-countries">
        <p class="cf-chart-label">Top countries</p>
        <div v-for="country in traffic.topCountries" :key="country.country" class="cf-country">
          <span>{{ country.country }}</span>
          <span class="cf-country-bar">
            <span :style="{ width: share(country.requests) + '%' }" />
          </span>
          <span class="cf-mono">{{ number(country.requests) }}</span>
        </div>
      </div>

      <p class="cf-muted cf-note">
        Page views leave out bots, crawlers and API calls; requests count
        everything that reached Cloudflare.
      </p>
    </template>
  </k-section>
</template>

<script>
import api from '../api.js';

const bytes = (b) => {
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  let i = 0;
  let n = b ?? 0;

  while (n >= 1024 && i < units.length - 1) {
    n /= 1024;
    i++;
  }

  return (i === 0 ? n : n.toFixed(1)) + ' ' + units[i];
};

export default {
  mixins: [api],
  data() {
    return {
      days: 7,
      ranges: [1, 7, 14, 30],
      traffic: null,
      error: null,
    };
  },
  computed: {
    reports() {
      const t = this.traffic.totals;

      return [
        { label: 'Page views', value: this.number(t.pageViews), icon: 'page' },
        { label: 'Unique visitors', value: this.number(t.uniques), icon: 'users' },
        { label: 'Requests', value: this.number(t.requests), info: 'bots included', icon: 'server' },
        { label: 'Data served', value: t.bytesHuman, info: t.cachedBytesHuman + ' from cache', icon: 'download' },
        { label: 'Cache hit rate', value: this.traffic.cacheHitRatio + '%', icon: 'bolt', theme: this.traffic.cacheHitRatio >= 50 ? 'positive' : null },
        { label: 'Error rate', value: this.traffic.errorRate + '%', info: '4xx and 5xx', icon: 'alert', theme: this.traffic.errorRate >= 5 ? 'negative' : null },
        { label: 'Threats', value: this.number(t.threats), icon: 'shield' },
      ];
    },
    charts() {
      return [
        { key: 'requests', label: 'Requests per day', format: (v) => this.number(v) + ' requests' },
        { key: 'uniques', label: 'Unique visitors per day', format: (v) => this.number(v) + ' visitors' },
        { key: 'bytes', label: 'Bandwidth per day', format: bytes },
      ];
    },
    first() {
      return this.traffic.series[0]?.date ?? '';
    },
    last() {
      return this.traffic.series.at(-1)?.date ?? '';
    },
  },
  created() {
    this.load();
  },
  methods: {
    async load() {
      this.error = null;
      this.traffic = null;

      try {
        this.traffic = await this.cf('get', 'analytics', { days: this.days });
      } catch (error) {
        this.error = error.message;
      }
    },
    select(days) {
      this.days = days;
      this.load();
    },
    height(value, key) {
      const max = Math.max(1, ...this.traffic.series.map((row) => row[key] ?? 0));

      return Math.max(2, Math.round(((value ?? 0) / max) * 100));
    },
    share(requests) {
      const max = Math.max(1, ...this.traffic.topCountries.map((c) => c.requests ?? 0));

      return Math.max(2, Math.round(((requests ?? 0) / max) * 100));
    },
  },
};
</script>

<style>
.cf-charts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
  gap: var(--spacing-3);
  margin-top: var(--spacing-3);
}
.cf-charts .cf-card + .cf-card {
  margin-top: 0;
}
.cf-chart-label {
  font-weight: var(--font-semi, 600);
  font-size: var(--text-sm);
  margin-bottom: var(--spacing-3);
}
.cf-chart {
  display: flex;
  align-items: flex-end;
  gap: 2px;
  height: 6rem;
}
.cf-chart-bar {
  flex: 1;
  min-width: 2px;
  background: var(--color-focus);
  border-radius: 2px 2px 0 0;
  opacity: 0.85;
}
.cf-chart-bar:hover {
  opacity: 1;
}
.cf-chart-range {
  display: flex;
  justify-content: space-between;
  margin-top: var(--spacing-2);
}
.cf-countries {
  margin-top: var(--spacing-3);
}
.cf-country {
  display: grid;
  grid-template-columns: minmax(6rem, 10rem) 1fr auto;
  align-items: center;
  gap: var(--spacing-3);
  font-size: var(--text-sm);
}
.cf-country + .cf-country {
  margin-top: var(--spacing-2);
}
.cf-country-bar {
  height: 0.5rem;
  background: var(--color-border);
  border-radius: var(--rounded-sm);
  overflow: hidden;
}
.cf-country-bar > span {
  display: block;
  height: 100%;
  background: var(--color-focus);
}
.cf-note {
  margin-top: var(--spacing-3);
}
</style>
