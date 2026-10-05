<template>
  <div>
    <settings-form group="security" label="Security settings" @saved="$emit('changed')" />

    <k-section label="Bots">
      <k-box v-if="error" theme="negative" icon="alert" :text="error" />
      <k-box v-else-if="botsError" theme="negative" icon="alert" :text="botsError" />
      <div v-else-if="bots" class="cf-card">
        <div class="cf-row">
          <div>
            <strong>Bot Fight Mode</strong>
            <p class="cf-muted">
              Challenges traffic that matches known bots. It can't be skipped
              for particular bots, so check monitoring and payment callbacks
              still get through.
            </p>
          </div>
          <k-toggle-input
            :key="'bots' + renders"
            :value="bots.fight_mode"
            :disabled="!writable || busy"
            :text="['Off', 'On']"
            @input="setBots({ fight_mode: $event })"
          />
        </div>
        <div v-if="bots.ai_bots_protection !== undefined" class="cf-row">
          <div>
            <strong>Block AI bots</strong>
            <p class="cf-muted">
              Blocks crawlers that collect content to train AI models.
              Cloudflare is replacing this with AI bot policies in its
              dashboard.
            </p>
          </div>
          <k-select-input
            :key="'ai' + renders"
            :value="bots.ai_bots_protection"
            :options="aiOptions"
            :disabled="!writable || busy"
            :empty="false"
            @input="setBots({ ai_bots_protection: $event })"
          />
        </div>
      </div>
    </k-section>

    <k-section label="IP Access Rules">
      <template #options>
        <k-button
          v-if="writable && !rulesError"
          icon="add"
          size="xs"
          variant="filled"
          text="Add rule"
          @click="addRule"
        />
      </template>

      <k-box v-if="rulesError" theme="negative" icon="alert" :text="rulesError" />
      <template v-else-if="rules">
        <k-table
          :columns="columns"
          :rows="rows"
          :options="writable ? ruleOptions : []"
          :index="false"
          empty="No rules yet. Block, challenge or allow an IP address, range, country or ASN."
          @option="onRuleOption"
        />
        <p class="cf-muted">
          Rules apply to every page of the zone. Account-wide rules show here
          too, and can only be changed in the Cloudflare dashboard.
        </p>
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
      busy: false,
      renders: 0,
      error: null,
      bots: null,
      botsError: null,
      rules: null,
      rulesError: null,
      modes: {},
      writable: false,
    };
  },
  computed: {
    aiOptions() {
      return [
        { value: 'block', text: 'Block on all pages' },
        { value: 'only_on_ad_pages', text: 'Block on pages with ads' },
        { value: 'disabled', text: 'Allow' },
      ];
    },
    columns() {
      return {
        value: { label: 'IP, range, country or ASN', type: 'text', mobile: true },
        mode: { label: 'Action', type: 'text', width: '1/5', mobile: true },
        notes: { label: 'Notes', type: 'text', width: '1/4' },
        scope: { label: 'Applies to', type: 'text', width: '1/6' },
      };
    },
    rows() {
      return (this.rules ?? []).map((rule) => ({
        ...rule,
        mode: this.modes[rule.mode] ?? rule.mode,
        scope: rule.scope === 'account' ? 'Whole account' : 'This zone',
      }));
    },
    ruleOptions() {
      return [{ icon: 'trash', text: 'Delete', click: 'delete' }];
    },
  },
  created() {
    this.load();
  },
  methods: {
    async load() {
      try {
        const response = await this.cf('get', 'security');

        this.bots = response.bots;
        this.botsError = response.botsError;
        this.rules = response.rules;
        this.rulesError = response.rulesError;
        this.modes = response.modes;
        this.writable = response.writable;
      } catch (error) {
        this.error = error.message;
      }
    },
    async setBots(change) {
      this.busy = true;

      try {
        this.bots = { ...this.bots, ...(await this.cf('patch', 'security/bots', change)) };
        this.$panel.notification.success('Saved');
      } catch (error) {
        this.notifyError(error);
      } finally {
        this.busy = false;
        this.renders++;
      }
    },
    async addRule() {
      const values = await this.form(
        {
          value: {
            label: 'IP address, range, country or ASN',
            type: 'text',
            required: true,
            placeholder: '203.0.113.7, 203.0.113.0/24, NZ or AS13335',
          },
          mode: {
            label: 'Action',
            type: 'select',
            empty: false,
            options: Object.entries(this.modes).map(([value, text]) => ({ value, text })),
          },
          notes: { label: 'Notes', type: 'text' },
        },
        { value: '', mode: 'block', notes: '' },
        { button: 'Add rule', icon: 'add' }
      );

      if (!values) return;

      try {
        const rule = await this.cf('post', 'security/rules', values);

        this.rules = [rule, ...this.rules];
        this.$panel.notification.success('Rule added');
      } catch (error) {
        this.notifyError(error);
      }
    },
    async onRuleOption(option, row) {
      if (option !== 'delete') return;

      if (row.scope === 'Whole account') {
        return this.$panel.notification.error('Account-wide rules can only be removed in the Cloudflare dashboard');
      }

      const ok = await this.confirm(`Delete the rule for ${this.$helper.string.escapeHTML(String(row.value))}?`, {
        button: 'Delete',
        theme: 'negative',
        icon: 'trash',
      });

      if (!ok) return;

      try {
        await this.cf('delete', 'security/rules/' + row.id);
        this.rules = this.rules.filter((rule) => rule.id !== row.id);
        this.$panel.notification.success('Rule deleted');
      } catch (error) {
        this.notifyError(error);
      }
    },
  },
};
</script>
