<template>
  <div>
    <k-section label="Purge cache">
      <div class="cf-card">
        <k-form
          :fields="purgeFields"
          :value="purge"
          @input="purge = $event"
          @submit="submitPurge"
        />
        <div class="cf-actions cf-purge-actions">
          <k-button
            :disabled="!can.purge || busy"
            icon="refresh"
            variant="filled"
            theme="notice"
            :text="purge.type === 'everything' ? 'Purge everything' : 'Purge'"
            @click="submitPurge"
          />
        </div>
      </div>
      <p class="cf-muted">
        Every purge method works on every plan. Free accounts may send five
        purges a minute; Cloudflare keeps the whole zone's limit, not this site's.
      </p>
    </k-section>

    <settings-form group="cache" label="Cache settings" @loaded="tiered = $event.tiered; tieredError = $event.tieredError" />

    <k-section label="Tiered Cache">
      <k-box v-if="tieredError" theme="negative" icon="alert" :text="tieredError" />
      <div v-else-if="tiered" class="cf-card">
        <div class="cf-row">
          <div>
            <strong>Tiered Cache</strong>
            <p class="cf-muted">Cache misses go through an upper-tier data centre before your server, so it sees fewer requests.</p>
          </div>
          <k-toggle-input
            :key="'tiered' + renders"
            :value="tiered.tiered"
            :disabled="!can.settings || busy"
            :text="['Off', 'On']"
            @input="setTiered({ tiered: $event })"
          />
        </div>
        <div class="cf-row">
          <div>
            <strong>Smart Tiered Cache topology</strong>
            <p class="cf-muted">Cloudflare picks the upper tier closest to your server.</p>
          </div>
          <k-toggle-input
            :key="'smart' + renders"
            :value="tiered.smart"
            :disabled="!can.settings || busy"
            :text="['Off', 'On']"
            @input="setTiered({ smart: $event })"
          />
        </div>
      </div>
    </k-section>
  </div>
</template>

<script>
import api from '../../api.js';
import SettingsForm from '../SettingsForm.vue';

const HELP = {
  everything: 'Clears every cached file for the zone, and Kirby\'s page cache.',
  urls: 'Full URLs, one per line, e.g. https://example.com/blog',
  prefixes: 'Host and path without https://, one per line, e.g. example.com/blog',
  hosts: 'Hostnames, one per line, e.g. www.example.com',
  tags: 'Cache-Tag header values your server sends, one per line',
};

export default {
  components: { SettingsForm },
  mixins: [api],
  props: {
    status: { type: Object, required: true },
  },
  data() {
    return {
      busy: false,
      renders: 0,
      purge: { type: 'urls', items: '' },
      tiered: null,
      tieredError: null,
    };
  },
  computed: {
    can() {
      return this.status.permissions ?? {};
    },
    purgeFields() {
      const fields = {
        type: {
          label: 'Purge',
          type: 'select',
          empty: false,
          width: '1/3',
          options: [
            { value: 'urls', text: 'Single URLs' },
            { value: 'prefixes', text: 'URL prefixes' },
            { value: 'hosts', text: 'Hostnames' },
            { value: 'tags', text: 'Cache tags' },
            { value: 'everything', text: 'Everything' },
          ],
          help: HELP[this.purge.type],
        },
      };

      if (this.purge.type !== 'everything') {
        fields.items = {
          label: 'Items',
          type: 'textarea',
          buttons: false,
          size: 'small',
          width: '2/3',
          font: 'monospace',
        };
      }

      return fields;
    },
  },
  methods: {
    async submitPurge() {
      if (this.purge.type === 'everything') {
        const ok = await this.confirm('Purge every file Cloudflare has cached for this site?', {
          button: 'Purge everything',
          icon: 'refresh',
        });

        if (!ok) return;
      }

      this.busy = true;

      try {
        const response = await this.cf('post', 'purge', {
          type: this.purge.type,
          items: this.purge.items ?? '',
        });

        this.$panel.notification.success(response.message);
        this.purge = { ...this.purge, items: '' };
      } catch (error) {
        this.notifyError(error);
      } finally {
        this.busy = false;
      }
    },
    async setTiered(change) {
      this.busy = true;

      try {
        const result = await this.cf('patch', 'tiered-cache', change);

        this.tiered = { ...this.tiered, ...result };
        this.$panel.notification.success('Saved');
      } catch (error) {
        this.notifyError(error);
      } finally {
        this.busy = false;
        this.renders++;
      }
    },
  },
};
</script>

<style>
.cf-purge-actions {
  margin-top: var(--spacing-3);
  justify-content: flex-end;
}
</style>
