<template>
  <div>
    <settings-form group="ssl" label="SSL/TLS settings" @saved="$emit('changed')" />

    <k-section label="Edge certificates">
      <k-box v-if="error" theme="negative" icon="alert" :text="error" />
      <k-empty v-else-if="!loaded" icon="loader">Loading…</k-empty>
      <template v-else>
        <k-box
          v-if="universal === false"
          theme="notice"
          icon="alert"
          text="Universal SSL is off: visitors only get HTTPS from a certificate you uploaded or ordered."
        />
        <k-table
          :columns="columns"
          :rows="rows"
          :index="false"
          empty="No edge certificates found."
        />
      </template>
    </k-section>
  </div>
</template>

<script>
import api from '../../api.js';
import SettingsForm from '../SettingsForm.vue';

export default {
  components: { SettingsForm },
  mixins: [api],
  props: {
    status: { type: Object, required: true },
  },
  emits: ['changed'],
  data() {
    return {
      loaded: false,
      error: null,
      universal: null,
      certificates: [],
    };
  },
  computed: {
    columns() {
      return {
        hosts: { label: 'Hosts', type: 'text', mobile: true },
        type: { label: 'Type', type: 'text', width: '1/6' },
        status: { label: 'Status', type: 'text', width: '1/6', mobile: true },
        authority: { label: 'Issuer', type: 'text', width: '1/6' },
        expires: { label: 'Expires', type: 'text', width: '1/6' },
      };
    },
    rows() {
      return this.certificates.map((pack) => ({
        ...pack,
        hosts: pack.hosts.join(', '),
        type: pack.type === 'universal' ? 'Universal (free)' : pack.type,
        authority: pack.authority ?? '–',
        expires: pack.expires ? pack.expires.slice(0, 10) : '–',
      }));
    },
  },
  async created() {
    try {
      const response = await this.cf('get', 'ssl');

      this.universal = response.universal;
      this.certificates = response.certificates;
      this.error = response.certificatesError;
    } catch (error) {
      this.error = error.message;
    } finally {
      this.loaded = true;
    }
  },
};
</script>
