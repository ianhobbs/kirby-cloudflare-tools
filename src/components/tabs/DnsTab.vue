<template>
  <k-section label="DNS records">
    <template #options>
      <k-button-group>
        <k-input
          v-model="search"
          type="text"
          icon="search"
          placeholder="Filter…"
          class="cf-dns-search"
        />
        <k-button
          v-if="writable"
          icon="add"
          size="xs"
          variant="filled"
          text="Add record"
          @click="edit()"
        />
      </k-button-group>
    </template>

    <k-box v-if="error" theme="negative" icon="alert" :text="error" />
    <k-empty v-else-if="!records" icon="loader">Loading…</k-empty>
    <template v-else>
      <k-table
        :columns="columns"
        :rows="rows"
        :options="writable ? options : []"
        :index="false"
        empty="No DNS records match."
        @option="onOption"
      />
      <p class="cf-muted">
        Proxied records (orange cloud) go through Cloudflare's cache and
        firewall; DNS-only records point straight at the server.
        <template v-if="!writable">
          Read-only here: set the plugin's <code>dns</code> option to
          <code>write</code>, and give your role the <code>dns</code>
          permission, to change records.
        </template>
      </p>
    </template>
  </k-section>
</template>

<script>
import api from '../../api.js';

export default {
  mixins: [api],
  props: {
    status: { type: Object, required: true },
  },
  data() {
    return {
      records: null,
      types: [],
      writable: false,
      error: null,
      search: '',
    };
  },
  computed: {
    columns() {
      return {
        type: { label: 'Type', type: 'text', width: '1/12', mobile: true },
        name: { label: 'Name', type: 'text', width: '1/4', mobile: true },
        content: { label: 'Content', type: 'text' },
        proxy: { label: 'Proxy', type: 'text', width: '1/8' },
        ttlText: { label: 'TTL', type: 'text', width: '1/12' },
      };
    },
    rows() {
      const query = this.search.trim().toLowerCase();

      return (this.records ?? [])
        .filter((record) => !query || [record.type, record.name, record.content, record.comment]
          .some((value) => (value ?? '').toString().toLowerCase().includes(query)))
        .map((record) => ({
          ...record,
          proxy: record.proxiable ? (record.proxied ? 'Proxied' : 'DNS only') : '–',
          ttlText: record.ttl === 1 ? 'Auto' : record.ttl + 's',
          content: record.priority !== null && record.priority !== undefined
            ? `${record.priority} ${record.content}`
            : record.content,
        }));
    },
    options() {
      return (row) => [
        { icon: 'edit', text: 'Edit', click: 'edit' },
        ...(row.proxiable
          ? [{ icon: 'cloud', text: row.proxied ? 'Turn proxy off' : 'Turn proxy on', click: 'proxy' }]
          : []),
        '-',
        { icon: 'trash', text: 'Delete', click: 'delete' },
      ];
    },
  },
  created() {
    this.load();
  },
  methods: {
    async load() {
      try {
        const response = await this.cf('get', 'dns');

        this.records = response.records;
        this.types = response.types;
        this.writable = response.writable;
      } catch (error) {
        this.error = error.message;
      }
    },
    record(id) {
      return this.records.find((record) => record.id === id);
    },
    replace(updated) {
      this.records = this.records.map((record) => (record.id === updated.id ? updated : record));
    },
    async onOption(option, row) {
      const record = this.record(row.id);

      if (option === 'edit') return this.edit(record);
      if (option === 'proxy') return this.toggleProxy(record);
      if (option === 'delete') return this.remove(record);
    },
    async edit(record = null) {
      const values = await this.form(
        {
          type: {
            label: 'Type',
            type: 'select',
            empty: false,
            width: '1/4',
            disabled: record !== null,
            options: this.types.map((type) => ({ value: type, text: type })),
          },
          name: { label: 'Name', type: 'text', required: true, width: '3/4', help: '@ for the domain itself' },
          content: { label: 'Content', type: 'text', required: true, font: 'monospace' },
          priority: { label: 'Priority', type: 'number', width: '1/4', help: 'MX and SRV only' },
          ttl: {
            label: 'TTL',
            type: 'select',
            empty: false,
            width: '1/4',
            options: [
              { value: '1', text: 'Auto' },
              { value: '300', text: '5 min' },
              { value: '3600', text: '1 hour' },
              { value: '86400', text: '1 day' },
            ],
          },
          proxied: { label: 'Proxied', type: 'toggle', width: '1/2', text: ['DNS only', 'Proxied'], help: 'A, AAAA and CNAME only' },
          comment: { label: 'Comment', type: 'text' },
        },
        record
          ? { ...record, ttl: String(record.ttl) }
          : { type: 'A', name: '', content: '', priority: null, ttl: '1', proxied: true, comment: '' },
        { button: record ? 'Save' : 'Add record', icon: record ? 'check' : 'add' }
      );

      if (!values) return;

      try {
        if (record) {
          const { type, ...changes } = values;

          // only A, AAAA and CNAME records can be proxied
          if (!record.proxiable) delete changes.proxied;

          this.replace(await this.cf('patch', 'dns/' + record.id, changes));
        } else {
          this.records = [await this.cf('post', 'dns', values), ...this.records];
        }

        this.$panel.notification.success('Saved');
      } catch (error) {
        this.notifyError(error);
      }
    },
    async toggleProxy(record) {
      try {
        this.replace(await this.cf('patch', 'dns/' + record.id, { proxied: !record.proxied }));
        this.$panel.notification.success(record.proxied ? 'Proxy off: DNS only' : 'Proxied through Cloudflare');
      } catch (error) {
        this.notifyError(error);
      }
    },
    async remove(record) {
      const ok = await this.confirm(
        `Delete the ${record.type} record for <strong>${this.$helper.string.escapeHTML(record.name)}</strong>? Anything that relies on it stops resolving.`,
        { button: 'Delete', theme: 'negative', icon: 'trash' }
      );

      if (!ok) return;

      try {
        await this.cf('delete', 'dns/' + record.id);
        this.records = this.records.filter((item) => item.id !== record.id);
        this.$panel.notification.success('Record deleted');
      } catch (error) {
        this.notifyError(error);
      }
    },
  },
};
</script>

<style>
.cf-dns-search {
  max-width: 12rem;
}
</style>
