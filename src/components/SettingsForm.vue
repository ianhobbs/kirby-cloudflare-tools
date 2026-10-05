<template>
  <k-section :label="label">
    <template #options>
      <k-button-group v-if="dirty.length">
        <k-button icon="undo" size="xs" variant="filled" text="Discard" @click="reset" />
        <k-button
          :disabled="saving"
          icon="check"
          size="xs"
          variant="filled"
          theme="positive"
          text="Save changes"
          @click="save"
        />
      </k-button-group>
    </template>

    <k-box v-if="error" theme="negative" icon="alert" :text="error" />
    <k-empty v-else-if="!fields" icon="loader">Loading…</k-empty>
    <k-empty v-else-if="Object.keys(fields).length === 0" icon="info">
      Cloudflare reports none of these settings for this zone.
    </k-empty>
    <template v-else>
      <k-form :fields="fields" :value="values" @input="values = $event" @submit="save" />
      <p v-if="readonly" class="cf-muted cf-readonly">
        Your role may view these settings but not change them.
      </p>
    </template>

    <slot :response="response" />
  </k-section>
</template>

<script>
import api from '../api.js';

export default {
  mixins: [api],
  props: {
    group: { type: String, required: true },
    label: { type: String, default: null },
  },
  emits: ['loaded', 'saved'],
  data() {
    return {
      fields: null,
      values: {},
      original: {},
      response: null,
      error: null,
      saving: false,
    };
  },
  computed: {
    dirty() {
      return Object.keys(this.values).filter(
        (id) => this.values[id] !== this.original[id]
      );
    },
    readonly() {
      return Object.values(this.fields ?? {}).length > 0 &&
        Object.values(this.fields).every((field) => field.disabled);
    },
  },
  created() {
    this.load();
  },
  methods: {
    async load() {
      this.error = null;

      try {
        const response = await this.cf('get', 'settings/' + this.group);

        this.response = response;
        this.fields = response.fields;
        this.values = { ...response.value };
        this.original = { ...response.value };
        this.$emit('loaded', response);
      } catch (error) {
        this.error = error.message;
      }
    },
    reset() {
      this.values = { ...this.original };
    },
    async save() {
      if (this.dirty.length === 0) return;

      const changes = {};

      for (const id of this.dirty) changes[id] = this.values[id];

      this.saving = true;

      try {
        const { saved, failed } = await this.cf('patch', 'settings/' + this.group, { changes });

        this.original = { ...this.original, ...saved };
        this.values = { ...this.values, ...saved };

        for (const [id, message] of Object.entries(failed)) {
          this.values[id] = this.original[id];
          this.$panel.notification.error(`${this.fields[id]?.label ?? id}: ${message}`);
        }

        const count = Object.keys(saved).length;
        const total = Object.keys(changes).length;

        if (count > 0) {
          this.$panel.notification.success(count === total ? 'Saved' : `Saved ${count} of ${total} changes`);
          this.$emit('saved', saved);
        }
      } catch (error) {
        this.notifyError(error);
      } finally {
        this.saving = false;
      }
    },
  },
};
</script>

<style>
.cf-readonly {
  margin-top: var(--spacing-3);
}
</style>
