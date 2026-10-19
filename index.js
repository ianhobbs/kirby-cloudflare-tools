(function() {
  "use strict";
  const base = "cloudflare-tools/";
  const api = {
    methods: {
      async cf(method, path, data = {}) {
        return this.$api[method](base + path, data);
      },
      notifyError(error) {
        this.$panel.notification.error((error == null ? void 0 : error.message) ?? String(error));
      },
      /** Opens a confirm dialog; resolves true when the user submits */
      confirm(text, { button = "OK", theme = "notice", icon = "alert" } = {}) {
        return new Promise((resolve) => {
          let answered = false;
          this.$panel.dialog.open({
            component: "k-text-dialog",
            props: {
              text,
              submitButton: { text: button, theme, icon }
            },
            on: {
              submit: () => {
                answered = true;
                this.$panel.dialog.close();
                resolve(true);
              },
              cancel: () => resolve(false),
              close: () => answered || resolve(false)
            }
          });
        });
      },
      /** Opens a form dialog; resolves with the values, or null when cancelled */
      form(fields, value = {}, { button = "Save", icon = "check" } = {}) {
        return new Promise((resolve) => {
          let answered = false;
          this.$panel.dialog.open({
            component: "k-form-dialog",
            props: {
              fields,
              value,
              submitButton: { text: button, icon, theme: "positive" }
            },
            on: {
              submit: (values) => {
                answered = true;
                this.$panel.dialog.close();
                resolve(values);
              },
              cancel: () => resolve(null),
              close: () => answered || resolve(null)
            }
          });
        });
      },
      number(value) {
        return (value ?? 0).toLocaleString();
      },
      duration(seconds) {
        const minutes = Math.round((seconds ?? 0) / 60);
        if (minutes < 60) return `${minutes} min`;
        return `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
      }
    }
  };
  function normalizeComponent(scriptExports, render, staticRenderFns, functionalTemplate, injectStyles, scopeId, moduleIdentifier, shadowMode) {
    var options = typeof scriptExports === "function" ? scriptExports.options : scriptExports;
    if (render) {
      options.render = render;
      options.staticRenderFns = staticRenderFns;
      options._compiled = true;
    }
    return {
      exports: scriptExports,
      options
    };
  }
  const _sfc_main$b = {
    props: {
      missing: { type: Array, default: () => [] }
    }
  };
  var _sfc_render$b = function render() {
    var _vm = this, _c = _vm._self._c;
    return _c("k-section", { attrs: { "label": "Connect Cloudflare" } }, [_c("k-box", { attrs: { "theme": "notice", "icon": "key" } }, [_c("k-text", [_c("p", [_vm._v(" Missing: "), _c("strong", [_vm._v(_vm._s(_vm.missing.join(" and ")))]), _vm._v(". ")]), _c("p", [_vm._v(" The plugin needs an "), _c("strong", [_vm._v("API token")]), _vm._v(" and the "), _c("strong", [_vm._v("Zone ID")]), _vm._v(" of your domain. Both stay on the server: put them in a "), _c("code", [_vm._v(".env")]), _vm._v(" file outside the web root and load it from "), _c("code", [_vm._v("site/config/config.php")]), _vm._v(": ")]), _c("pre", { staticClass: "cf-mono" }, [_vm._v("CLOUDFLARE_API_TOKEN=…\nCLOUDFLARE_ZONE_ID=…")]), _c("p", [_vm._v(" The Zone ID is on your domain's "), _c("em", [_vm._v("Overview")]), _vm._v(" page in the Cloudflare dashboard. Create the token under "), _c("em", [_vm._v("My Profile › API Tokens")]), _vm._v('. See the plugin README, "Credentials", for the permissions each feature needs. ')])])], 1)], 1);
  };
  var _sfc_staticRenderFns$b = [];
  _sfc_render$b._withStripped = true;
  var __component__$b = /* @__PURE__ */ normalizeComponent(
    _sfc_main$b,
    _sfc_render$b,
    _sfc_staticRenderFns$b
  );
  __component__$b.options.__file = "/Volumes/2000/Sites/2026-CloudflarePlugin/src/components/SetupNotice.vue";
  const SetupNotice = __component__$b.exports;
  const bytes = (b) => {
    const units = ["B", "KB", "MB", "GB", "TB", "PB"];
    let i = 0;
    let n = b ?? 0;
    while (n >= 1024 && i < units.length - 1) {
      n /= 1024;
      i++;
    }
    return (i === 0 ? n : n.toFixed(1)) + " " + units[i];
  };
  const _sfc_main$a = {
    mixins: [api],
    data() {
      return {
        days: 7,
        ranges: [1, 7, 14, 30],
        traffic: null,
        error: null
      };
    },
    computed: {
      reports() {
        const t = this.traffic.totals;
        return [
          { label: "Page views", value: this.number(t.pageViews), icon: "page" },
          { label: "Unique visitors", value: this.number(t.uniques), icon: "users" },
          { label: "Requests", value: this.number(t.requests), info: "bots included", icon: "server" },
          { label: "Data served", value: t.bytesHuman, info: t.cachedBytesHuman + " from cache", icon: "download" },
          { label: "Cache hit rate", value: this.traffic.cacheHitRatio + "%", icon: "bolt", theme: this.traffic.cacheHitRatio >= 50 ? "positive" : null },
          { label: "Error rate", value: this.traffic.errorRate + "%", info: "4xx and 5xx", icon: "alert", theme: this.traffic.errorRate >= 5 ? "negative" : null },
          { label: "Threats", value: this.number(t.threats), icon: "shield" }
        ];
      },
      charts() {
        return [
          { key: "requests", label: "Requests per day", format: (v) => this.number(v) + " requests" },
          { key: "uniques", label: "Unique visitors per day", format: (v) => this.number(v) + " visitors" },
          { key: "bytes", label: "Bandwidth per day", format: bytes }
        ];
      },
      first() {
        var _a;
        return ((_a = this.traffic.series[0]) == null ? void 0 : _a.date) ?? "";
      },
      last() {
        var _a;
        return ((_a = this.traffic.series.at(-1)) == null ? void 0 : _a.date) ?? "";
      }
    },
    created() {
      this.load();
    },
    methods: {
      async load() {
        this.error = null;
        this.traffic = null;
        try {
          this.traffic = await this.cf("get", "analytics", { days: this.days });
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
        return Math.max(2, Math.round((value ?? 0) / max * 100));
      },
      share(requests) {
        const max = Math.max(1, ...this.traffic.topCountries.map((c) => c.requests ?? 0));
        return Math.max(2, Math.round((requests ?? 0) / max * 100));
      }
    }
  };
  var _sfc_render$a = function render() {
    var _vm = this, _c = _vm._self._c;
    return _c("k-section", { attrs: { "label": "Traffic" }, scopedSlots: _vm._u([{ key: "options", fn: function() {
      return [_c("k-button-group", _vm._l(_vm.ranges, function(option) {
        return _c("k-button", { key: option, attrs: { "current": _vm.days === option, "text": option === 1 ? "Today" : option + " days", "size": "xs", "variant": "filled" }, on: { "click": function($event) {
          return _vm.select(option);
        } } });
      }), 1)];
    }, proxy: true }]) }, [_vm.error ? _c("k-box", { attrs: { "theme": "negative", "icon": "alert", "text": _vm.error } }) : !_vm.traffic ? _c("k-empty", { attrs: { "icon": "loader" } }, [_vm._v("Loading traffic…")]) : [_c("k-stats", { attrs: { "reports": _vm.reports, "size": "small" } }), _c("div", { staticClass: "cf-charts" }, _vm._l(_vm.charts, function(chart) {
      return _c("div", { key: chart.key, staticClass: "cf-card" }, [_c("p", { staticClass: "cf-chart-label" }, [_vm._v(_vm._s(chart.label))]), _c("div", { staticClass: "cf-chart", attrs: { "role": "img", "aria-label": chart.label } }, _vm._l(_vm.traffic.series, function(row) {
        return _c("span", { key: row.date, staticClass: "cf-chart-bar", style: { height: _vm.height(row[chart.key], chart.key) + "%" }, attrs: { "title": row.date + ": " + chart.format(row[chart.key]) } });
      }), 0), _c("p", { staticClass: "cf-muted cf-chart-range" }, [_c("span", [_vm._v(_vm._s(_vm.first))]), _c("span", [_vm._v(_vm._s(_vm.last))])])]);
    }), 0), _vm.traffic.topCountries.length ? _c("div", { staticClass: "cf-card cf-countries" }, [_c("p", { staticClass: "cf-chart-label" }, [_vm._v("Top countries")]), _vm._l(_vm.traffic.topCountries, function(country) {
      return _c("div", { key: country.country, staticClass: "cf-country" }, [_c("span", [_vm._v(_vm._s(country.country))]), _c("span", { staticClass: "cf-country-bar" }, [_c("span", { style: { width: _vm.share(country.requests) + "%" } })]), _c("span", { staticClass: "cf-mono" }, [_vm._v(_vm._s(_vm.number(country.requests)))])]);
    })], 2) : _vm._e(), _c("p", { staticClass: "cf-muted cf-note" }, [_vm._v(" Page views leave out bots, crawlers and API calls; requests count everything that reached Cloudflare. ")])]], 2);
  };
  var _sfc_staticRenderFns$a = [];
  _sfc_render$a._withStripped = true;
  var __component__$a = /* @__PURE__ */ normalizeComponent(
    _sfc_main$a,
    _sfc_render$a,
    _sfc_staticRenderFns$a
  );
  __component__$a.options.__file = "/Volumes/2000/Sites/2026-CloudflarePlugin/src/components/AnalyticsPanel.vue";
  const AnalyticsPanel = __component__$a.exports;
  const _sfc_main$9 = {
    props: {
      license: { type: Object, default: null }
    },
    emits: ["changed"],
    computed: {
      visible() {
        var _a;
        return ["missing", "inactive", "unknown"].includes((_a = this.license) == null ? void 0 : _a.status);
      },
      theme() {
        return this.license.status === "inactive" ? "negative" : "notice";
      },
      heading() {
        return {
          missing: "Unlicensed website",
          inactive: "Licence inactive",
          unknown: "Licence not verified"
        }[this.license.status];
      }
    },
    methods: {
      open() {
        this.$panel.dialog.open("cloudflare-tools/license", {
          on: {
            success: (response) => {
              this.$panel.dialog.close();
              this.$panel.notification.success((response == null ? void 0 : response.message) ?? "Saved");
              this.$emit("changed");
            }
          }
        });
      }
    }
  };
  var _sfc_render$9 = function render() {
    var _vm = this, _c = _vm._self._c;
    return _vm.visible ? _c("k-box", { staticClass: "cf-license-notice", attrs: { "theme": _vm.theme, "icon": "key" } }, [_c("div", { staticClass: "cf-row" }, [_c("div", [_c("strong", [_vm._v(_vm._s(_vm.heading))]), _c("p", [_vm._v(_vm._s(_vm.license.message))])]), _c("k-button-group", [_vm.license.shop && _vm.license.status !== "unknown" ? _c("k-button", { attrs: { "link": _vm.license.shop, "target": "_blank", "icon": "cart", "size": "sm", "variant": "filled", "text": "Buy a licence" } }) : _vm._e(), _vm.license.manage ? _c("k-button", { attrs: { "icon": "key", "size": "sm", "variant": "filled", "theme": _vm.theme, "text": _vm.license.source ? "Manage licence" : "Enter licence key" }, on: { "click": _vm.open } }) : _vm._e()], 1)], 1)]) : _vm._e();
  };
  var _sfc_staticRenderFns$9 = [];
  _sfc_render$9._withStripped = true;
  var __component__$9 = /* @__PURE__ */ normalizeComponent(
    _sfc_main$9,
    _sfc_render$9,
    _sfc_staticRenderFns$9
  );
  __component__$9.options.__file = "/Volumes/2000/Sites/2026-CloudflarePlugin/src/components/LicenseNotice.vue";
  const LicenseNotice = __component__$9.exports;
  const LEVELS = {
    essentially_off: "Essentially off",
    low: "Low",
    medium: "Medium",
    high: "High",
    under_attack: "I'm Under Attack!"
  };
  const SSL = {
    off: "Off",
    flexible: "Flexible",
    full: "Full",
    strict: "Full (strict)"
  };
  const _sfc_main$8 = {
    components: { AnalyticsPanel, LicenseNotice },
    mixins: [api],
    props: {
      status: { type: Object, required: true }
    },
    emits: ["changed"],
    data() {
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
        const active = zone.status === "active" && !zone.paused;
        const reports = [
          {
            label: "Domain",
            value: zone.name ?? "–",
            info: zone.paused ? "Paused" : zone.status,
            theme: active ? "positive" : "notice",
            icon: "globe"
          },
          {
            label: "Plan",
            value: zone.plan ?? "–",
            info: zone.account,
            icon: "cloud"
          }
        ];
        if (this.settings) {
          reports.push(
            {
              label: "Security level",
              value: LEVELS[settings.securityLevel] ?? settings.securityLevel,
              theme: settings.securityLevel === "under_attack" ? "negative" : null,
              icon: "shield"
            },
            {
              label: "SSL/TLS",
              value: SSL[settings.ssl] ?? settings.ssl,
              info: settings.alwaysUseHttps ? "Always HTTPS" : "HTTP allowed",
              theme: settings.ssl === "off" || settings.ssl === "flexible" ? "notice" : "positive",
              icon: "lock"
            }
          );
        }
        return reports;
      }
    },
    methods: {
      async run(action, success) {
        this.busy = true;
        try {
          const response = await action();
          this.$panel.notification.success(success ?? (response == null ? void 0 : response.message) ?? "Done");
          this.$emit("changed");
        } catch (error) {
          this.notifyError(error);
        } finally {
          this.busy = false;
          this.renders++;
        }
      },
      async purgeEverything() {
        const ok = await this.confirm(
          "Purge every file Cloudflare has cached for this site? Visitors will be served fresh copies, and your server will see more traffic for a while.",
          { button: "Purge everything", icon: "refresh" }
        );
        if (ok) await this.run(() => this.cf("post", "purge", { type: "everything" }));
      },
      toggleDevelopmentMode(on) {
        this.run(
          () => this.cf("patch", "settings/cache", { changes: { development_mode: on } }),
          on ? "Development Mode is on for three hours" : "Development Mode is off"
        );
      },
      async toggleUnderAttack(on) {
        if (on) {
          const ok = await this.confirm(
            "Turn on I'm Under Attack mode? Every visitor gets a browser check before the site loads.",
            { button: "Turn on", theme: "negative", icon: "shield" }
          );
          if (!ok) return this.renders++;
        }
        this.run(() => this.cf("post", "under-attack", { enabled: on }));
      },
      async togglePause() {
        const paused = !this.status.zone.paused;
        if (paused) {
          const ok = await this.confirm(
            "Pause Cloudflare for this site? Traffic goes straight to your server, without caching or protection, until you resume.",
            { button: "Pause Cloudflare", theme: "negative", icon: "shut-down" }
          );
          if (!ok) return;
        }
        this.run(
          () => this.cf("post", "zone/pause", { paused }),
          paused ? "Cloudflare is paused" : "Cloudflare is active again"
        );
      }
    }
  };
  var _sfc_render$8 = function render() {
    var _vm = this, _c = _vm._self._c;
    return _c("div", { staticClass: "cf-overview" }, [_c("license-notice", { attrs: { "license": _vm.status.license }, on: { "changed": function($event) {
      return _vm.$emit("changed");
    } } }), _c("k-section", { attrs: { "label": "Zone" } }, [_vm.status.zoneError ? _c("k-box", { attrs: { "theme": "negative", "icon": "alert", "text": _vm.status.zoneError } }) : _c("k-stats", { attrs: { "reports": _vm.zoneReports, "size": "large" } })], 1), _c("k-section", { attrs: { "label": "Quick actions" } }, [_c("div", { staticClass: "cf-card" }, [_c("div", { staticClass: "cf-row" }, [_c("div", [_c("strong", [_vm._v("Purge everything")]), _c("p", { staticClass: "cf-muted" }, [_vm._v(" Clears every file Cloudflare has cached for this site (and Kirby's page cache first). Visitors get fresh copies from your server. ")])]), _c("k-button", { attrs: { "disabled": !_vm.can.purge || _vm.busy, "icon": "refresh", "variant": "filled", "theme": "notice", "text": "Purge everything" }, on: { "click": _vm.purgeEverything } })], 1), _vm.settings ? _c("div", { staticClass: "cf-row" }, [_c("div", [_c("strong", [_vm._v("Development Mode")]), _c("p", { staticClass: "cf-muted" }, [_vm._v(" Bypasses the cache for three hours while you work on the site. "), _vm.settings.developmentMode && _vm.settings.developmentLeft ? [_vm._v(" " + _vm._s(_vm.duration(_vm.settings.developmentLeft)) + " left. ")] : _vm._e()], 2)]), _c("k-toggle-input", { key: "dev" + _vm.renders, attrs: { "value": _vm.settings.developmentMode, "disabled": !_vm.can.settings || _vm.busy, "text": ["Off", "On"] }, on: { "input": _vm.toggleDevelopmentMode } })], 1) : _vm._e(), _vm.settings ? _c("div", { staticClass: "cf-row" }, [_c("div", [_c("strong", [_vm._v("I'm Under Attack")]), _c("p", { staticClass: "cf-muted" }, [_vm._v(" Shows every visitor a short check before the site loads. Use it only during an attack; turning it off restores the previous level. ")])]), _c("k-toggle-input", { key: "attack" + _vm.renders, attrs: { "value": _vm.settings.securityLevel === "under_attack", "disabled": !_vm.can.security || _vm.busy, "text": ["Off", "On"] }, on: { "input": _vm.toggleUnderAttack } })], 1) : _vm._e(), _vm.status.zone ? _c("div", { staticClass: "cf-row" }, [_c("div", [_c("strong", [_vm._v(_vm._s(_vm.status.zone.paused ? "Resume Cloudflare" : "Pause Cloudflare"))]), _c("p", { staticClass: "cf-muted" }, [_vm._v(" Pausing sends traffic straight to your server: no cache, no firewall, no protection. DNS keeps working. ")])]), _c("k-button", { attrs: { "disabled": !_vm.can.settings || _vm.busy, "theme": _vm.status.zone.paused ? "positive" : "negative", "icon": _vm.status.zone.paused ? "play" : "shut-down", "variant": "filled", "text": _vm.status.zone.paused ? "Resume" : "Pause" }, on: { "click": _vm.togglePause } })], 1) : _vm._e()]), _vm.status.settingsError ? _c("p", { staticClass: "cf-muted" }, [_vm._v(" Zone settings unavailable: " + _vm._s(_vm.status.settingsError) + " ")]) : _vm._e()]), _vm.can.analytics ? _c("analytics-panel") : _vm._e(), _vm.status.zone && _vm.status.zone.nameServers.length ? _c("k-section", { attrs: { "label": "Cloudflare name servers" } }, [_c("div", { staticClass: "cf-card cf-mono" }, _vm._l(_vm.status.zone.nameServers, function(server) {
      return _c("div", { key: server }, [_vm._v(_vm._s(server))]);
    }), 0)]) : _vm._e()], 1);
  };
  var _sfc_staticRenderFns$8 = [];
  _sfc_render$8._withStripped = true;
  var __component__$8 = /* @__PURE__ */ normalizeComponent(
    _sfc_main$8,
    _sfc_render$8,
    _sfc_staticRenderFns$8
  );
  __component__$8.options.__file = "/Volumes/2000/Sites/2026-CloudflarePlugin/src/components/tabs/OverviewTab.vue";
  const OverviewTab = __component__$8.exports;
  const _sfc_main$7 = {
    mixins: [api],
    props: {
      group: { type: String, required: true },
      label: { type: String, default: null }
    },
    emits: ["loaded", "saved"],
    data() {
      return {
        fields: null,
        values: {},
        original: {},
        response: null,
        error: null,
        saving: false
      };
    },
    computed: {
      dirty() {
        return Object.keys(this.values).filter(
          (id) => this.values[id] !== this.original[id]
        );
      },
      readonly() {
        return Object.values(this.fields ?? {}).length > 0 && Object.values(this.fields).every((field) => field.disabled);
      }
    },
    created() {
      this.load();
    },
    methods: {
      async load() {
        this.error = null;
        try {
          const response = await this.cf("get", "settings/" + this.group);
          this.response = response;
          this.fields = response.fields;
          this.values = { ...response.value };
          this.original = { ...response.value };
          this.$emit("loaded", response);
        } catch (error) {
          this.error = error.message;
        }
      },
      reset() {
        this.values = { ...this.original };
      },
      async save() {
        var _a;
        if (this.dirty.length === 0) return;
        const changes = {};
        for (const id of this.dirty) changes[id] = this.values[id];
        this.saving = true;
        try {
          const { saved, failed } = await this.cf("patch", "settings/" + this.group, { changes });
          this.original = { ...this.original, ...saved };
          this.values = { ...this.values, ...saved };
          for (const [id, message] of Object.entries(failed)) {
            this.values[id] = this.original[id];
            this.$panel.notification.error(`${((_a = this.fields[id]) == null ? void 0 : _a.label) ?? id}: ${message}`);
          }
          const count = Object.keys(saved).length;
          const total = Object.keys(changes).length;
          if (count > 0) {
            this.$panel.notification.success(count === total ? "Saved" : `Saved ${count} of ${total} changes`);
            this.$emit("saved", saved);
          }
        } catch (error) {
          this.notifyError(error);
        } finally {
          this.saving = false;
        }
      }
    }
  };
  var _sfc_render$7 = function render() {
    var _vm = this, _c = _vm._self._c;
    return _c("k-section", { attrs: { "label": _vm.label }, scopedSlots: _vm._u([{ key: "options", fn: function() {
      return [_vm.dirty.length ? _c("k-button-group", [_c("k-button", { attrs: { "icon": "undo", "size": "xs", "variant": "filled", "text": "Discard" }, on: { "click": _vm.reset } }), _c("k-button", { attrs: { "disabled": _vm.saving, "icon": "check", "size": "xs", "variant": "filled", "theme": "positive", "text": "Save changes" }, on: { "click": _vm.save } })], 1) : _vm._e()];
    }, proxy: true }]) }, [_vm.error ? _c("k-box", { attrs: { "theme": "negative", "icon": "alert", "text": _vm.error } }) : !_vm.fields ? _c("k-empty", { attrs: { "icon": "loader" } }, [_vm._v("Loading…")]) : Object.keys(_vm.fields).length === 0 ? _c("k-empty", { attrs: { "icon": "info" } }, [_vm._v(" Cloudflare reports none of these settings for this zone. ")]) : [_c("k-form", { attrs: { "fields": _vm.fields, "value": _vm.values }, on: { "input": function($event) {
      _vm.values = $event;
    }, "submit": _vm.save } }), _vm.readonly ? _c("p", { staticClass: "cf-muted cf-readonly" }, [_vm._v(" Your role may view these settings but not change them. ")]) : _vm._e()], _vm._t("default", null, { "response": _vm.response })], 2);
  };
  var _sfc_staticRenderFns$7 = [];
  _sfc_render$7._withStripped = true;
  var __component__$7 = /* @__PURE__ */ normalizeComponent(
    _sfc_main$7,
    _sfc_render$7,
    _sfc_staticRenderFns$7
  );
  __component__$7.options.__file = "/Volumes/2000/Sites/2026-CloudflarePlugin/src/components/SettingsForm.vue";
  const SettingsForm = __component__$7.exports;
  const HELP = {
    everything: "Clears every cached file for the zone, and Kirby's page cache.",
    urls: "Full URLs, one per line, e.g. https://example.com/blog",
    prefixes: "Host and path without https://, one per line, e.g. example.com/blog",
    hosts: "Hostnames, one per line, e.g. www.example.com",
    tags: "Cache-Tag header values your server sends, one per line"
  };
  const _sfc_main$6 = {
    components: { SettingsForm },
    mixins: [api],
    props: {
      status: { type: Object, required: true }
    },
    data() {
      return {
        busy: false,
        renders: 0,
        purge: { type: "urls", items: "" },
        tiered: null,
        tieredError: null
      };
    },
    computed: {
      can() {
        return this.status.permissions ?? {};
      },
      purgeFields() {
        const fields = {
          type: {
            label: "Purge",
            type: "select",
            empty: false,
            width: "1/3",
            options: [
              { value: "urls", text: "Single URLs" },
              { value: "prefixes", text: "URL prefixes" },
              { value: "hosts", text: "Hostnames" },
              { value: "tags", text: "Cache tags" },
              { value: "everything", text: "Everything" }
            ],
            help: HELP[this.purge.type]
          }
        };
        if (this.purge.type !== "everything") {
          fields.items = {
            label: "Items",
            type: "textarea",
            buttons: false,
            size: "small",
            width: "2/3",
            font: "monospace"
          };
        }
        return fields;
      }
    },
    methods: {
      async submitPurge() {
        if (this.purge.type === "everything") {
          const ok = await this.confirm("Purge every file Cloudflare has cached for this site?", {
            button: "Purge everything",
            icon: "refresh"
          });
          if (!ok) return;
        }
        this.busy = true;
        try {
          const response = await this.cf("post", "purge", {
            type: this.purge.type,
            items: this.purge.items ?? ""
          });
          this.$panel.notification.success(response.message);
          this.purge = { ...this.purge, items: "" };
        } catch (error) {
          this.notifyError(error);
        } finally {
          this.busy = false;
        }
      },
      async setTiered(change) {
        this.busy = true;
        try {
          const result = await this.cf("patch", "tiered-cache", change);
          this.tiered = { ...this.tiered, ...result };
          this.$panel.notification.success("Saved");
        } catch (error) {
          this.notifyError(error);
        } finally {
          this.busy = false;
          this.renders++;
        }
      }
    }
  };
  var _sfc_render$6 = function render() {
    var _vm = this, _c = _vm._self._c;
    return _c("div", [_c("k-section", { attrs: { "label": "Purge cache" } }, [_c("div", { staticClass: "cf-card" }, [_c("k-form", { attrs: { "fields": _vm.purgeFields, "value": _vm.purge }, on: { "input": function($event) {
      _vm.purge = $event;
    }, "submit": _vm.submitPurge } }), _c("div", { staticClass: "cf-actions cf-purge-actions" }, [_c("k-button", { attrs: { "disabled": !_vm.can.purge || _vm.busy, "icon": "refresh", "variant": "filled", "theme": "notice", "text": _vm.purge.type === "everything" ? "Purge everything" : "Purge" }, on: { "click": _vm.submitPurge } })], 1)], 1), _c("p", { staticClass: "cf-muted" }, [_vm._v(" Every purge method works on every plan. Free accounts may send five purges a minute; Cloudflare keeps the whole zone's limit, not this site's. ")])]), _c("settings-form", { attrs: { "group": "cache", "label": "Cache settings" }, on: { "loaded": function($event) {
      _vm.tiered = $event.tiered;
      _vm.tieredError = $event.tieredError;
    } } }), _c("k-section", { attrs: { "label": "Tiered Cache" } }, [_vm.tieredError ? _c("k-box", { attrs: { "theme": "negative", "icon": "alert", "text": _vm.tieredError } }) : _vm.tiered ? _c("div", { staticClass: "cf-card" }, [_c("div", { staticClass: "cf-row" }, [_c("div", [_c("strong", [_vm._v("Tiered Cache")]), _c("p", { staticClass: "cf-muted" }, [_vm._v("Cache misses go through an upper-tier data centre before your server, so it sees fewer requests.")])]), _c("k-toggle-input", { key: "tiered" + _vm.renders, attrs: { "value": _vm.tiered.tiered, "disabled": !_vm.can.settings || _vm.busy, "text": ["Off", "On"] }, on: { "input": function($event) {
      return _vm.setTiered({ tiered: $event });
    } } })], 1), _c("div", { staticClass: "cf-row" }, [_c("div", [_c("strong", [_vm._v("Smart Tiered Cache topology")]), _c("p", { staticClass: "cf-muted" }, [_vm._v("Cloudflare picks the upper tier closest to your server.")])]), _c("k-toggle-input", { key: "smart" + _vm.renders, attrs: { "value": _vm.tiered.smart, "disabled": !_vm.can.settings || _vm.busy, "text": ["Off", "On"] }, on: { "input": function($event) {
      return _vm.setTiered({ smart: $event });
    } } })], 1)]) : _vm._e()], 1)], 1);
  };
  var _sfc_staticRenderFns$6 = [];
  _sfc_render$6._withStripped = true;
  var __component__$6 = /* @__PURE__ */ normalizeComponent(
    _sfc_main$6,
    _sfc_render$6,
    _sfc_staticRenderFns$6
  );
  __component__$6.options.__file = "/Volumes/2000/Sites/2026-CloudflarePlugin/src/components/tabs/CacheTab.vue";
  const CacheTab = __component__$6.exports;
  const _sfc_main$5 = {
    components: { SettingsForm },
    mixins: [api],
    props: {
      status: { type: Object, required: true }
    },
    emits: ["changed"],
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
        writable: false
      };
    },
    computed: {
      aiOptions() {
        return [
          { value: "block", text: "Block on all pages" },
          { value: "only_on_ad_pages", text: "Block on pages with ads" },
          { value: "disabled", text: "Allow" }
        ];
      },
      columns() {
        return {
          value: { label: "IP, range, country or ASN", type: "text", mobile: true },
          mode: { label: "Action", type: "text", width: "1/5", mobile: true },
          notes: { label: "Notes", type: "text", width: "1/4" },
          scope: { label: "Applies to", type: "text", width: "1/6" }
        };
      },
      rows() {
        return (this.rules ?? []).map((rule) => ({
          ...rule,
          mode: this.modes[rule.mode] ?? rule.mode,
          scope: rule.scope === "account" ? "Whole account" : "This zone"
        }));
      },
      ruleOptions() {
        return [{ icon: "trash", text: "Delete", click: "delete" }];
      }
    },
    created() {
      this.load();
    },
    methods: {
      async load() {
        try {
          const response = await this.cf("get", "security");
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
          this.bots = { ...this.bots, ...await this.cf("patch", "security/bots", change) };
          this.$panel.notification.success("Saved");
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
              label: "IP address, range, country or ASN",
              type: "text",
              required: true,
              placeholder: "203.0.113.7, 203.0.113.0/24, NZ or AS13335"
            },
            mode: {
              label: "Action",
              type: "select",
              empty: false,
              options: Object.entries(this.modes).map(([value, text]) => ({ value, text }))
            },
            notes: { label: "Notes", type: "text" }
          },
          { value: "", mode: "block", notes: "" },
          { button: "Add rule", icon: "add" }
        );
        if (!values) return;
        try {
          const rule = await this.cf("post", "security/rules", values);
          this.rules = [rule, ...this.rules];
          this.$panel.notification.success("Rule added");
        } catch (error) {
          this.notifyError(error);
        }
      },
      async onRuleOption(option, row) {
        if (option !== "delete") return;
        if (row.scope === "Whole account") {
          return this.$panel.notification.error("Account-wide rules can only be removed in the Cloudflare dashboard");
        }
        const ok = await this.confirm(`Delete the rule for ${this.$helper.string.escapeHTML(String(row.value))}?`, {
          button: "Delete",
          theme: "negative",
          icon: "trash"
        });
        if (!ok) return;
        try {
          await this.cf("delete", "security/rules/" + row.id);
          this.rules = this.rules.filter((rule) => rule.id !== row.id);
          this.$panel.notification.success("Rule deleted");
        } catch (error) {
          this.notifyError(error);
        }
      }
    }
  };
  var _sfc_render$5 = function render() {
    var _vm = this, _c = _vm._self._c;
    return _c("div", [_c("settings-form", { attrs: { "group": "security", "label": "Security settings" }, on: { "saved": function($event) {
      return _vm.$emit("changed");
    } } }), _c("k-section", { attrs: { "label": "Bots" } }, [_vm.error ? _c("k-box", { attrs: { "theme": "negative", "icon": "alert", "text": _vm.error } }) : _vm.botsError ? _c("k-box", { attrs: { "theme": "negative", "icon": "alert", "text": _vm.botsError } }) : _vm.bots ? _c("div", { staticClass: "cf-card" }, [_c("div", { staticClass: "cf-row" }, [_c("div", [_c("strong", [_vm._v("Bot Fight Mode")]), _c("p", { staticClass: "cf-muted" }, [_vm._v(" Challenges traffic that matches known bots. It can't be skipped for particular bots, so check monitoring and payment callbacks still get through. ")])]), _c("k-toggle-input", { key: "bots" + _vm.renders, attrs: { "value": _vm.bots.fight_mode, "disabled": !_vm.writable || _vm.busy, "text": ["Off", "On"] }, on: { "input": function($event) {
      return _vm.setBots({ fight_mode: $event });
    } } })], 1), _vm.bots.ai_bots_protection !== void 0 ? _c("div", { staticClass: "cf-row" }, [_c("div", [_c("strong", [_vm._v("Block AI bots")]), _c("p", { staticClass: "cf-muted" }, [_vm._v(" Blocks crawlers that collect content to train AI models. Cloudflare is replacing this with AI bot policies in its dashboard. ")])]), _c("k-select-input", { key: "ai" + _vm.renders, attrs: { "value": _vm.bots.ai_bots_protection, "options": _vm.aiOptions, "disabled": !_vm.writable || _vm.busy, "empty": false }, on: { "input": function($event) {
      return _vm.setBots({ ai_bots_protection: $event });
    } } })], 1) : _vm._e()]) : _vm._e()], 1), _c("k-section", { attrs: { "label": "IP Access Rules" }, scopedSlots: _vm._u([{ key: "options", fn: function() {
      return [_vm.writable && !_vm.rulesError ? _c("k-button", { attrs: { "icon": "add", "size": "xs", "variant": "filled", "text": "Add rule" }, on: { "click": _vm.addRule } }) : _vm._e()];
    }, proxy: true }]) }, [_vm.rulesError ? _c("k-box", { attrs: { "theme": "negative", "icon": "alert", "text": _vm.rulesError } }) : _vm.rules ? [_c("k-table", { attrs: { "columns": _vm.columns, "rows": _vm.rows, "options": _vm.writable ? _vm.ruleOptions : [], "index": false, "empty": "No rules yet. Block, challenge or allow an IP address, range, country or ASN." }, on: { "option": _vm.onRuleOption } }), _c("p", { staticClass: "cf-muted" }, [_vm._v(" Rules apply to every page of the zone. Account-wide rules show here too, and can only be changed in the Cloudflare dashboard. ")])] : _vm._e()], 2)], 1);
  };
  var _sfc_staticRenderFns$5 = [];
  _sfc_render$5._withStripped = true;
  var __component__$5 = /* @__PURE__ */ normalizeComponent(
    _sfc_main$5,
    _sfc_render$5,
    _sfc_staticRenderFns$5
  );
  __component__$5.options.__file = "/Volumes/2000/Sites/2026-CloudflarePlugin/src/components/tabs/SecurityTab.vue";
  const SecurityTab = __component__$5.exports;
  const _sfc_main$4 = {
    components: { SettingsForm },
    mixins: [api],
    props: {
      status: { type: Object, required: true }
    },
    emits: ["changed"],
    data() {
      return {
        loaded: false,
        error: null,
        universal: null,
        certificates: []
      };
    },
    computed: {
      columns() {
        return {
          hosts: { label: "Hosts", type: "text", mobile: true },
          type: { label: "Type", type: "text", width: "1/6" },
          status: { label: "Status", type: "text", width: "1/6", mobile: true },
          authority: { label: "Issuer", type: "text", width: "1/6" },
          expires: { label: "Expires", type: "text", width: "1/6" }
        };
      },
      rows() {
        return this.certificates.map((pack) => ({
          ...pack,
          hosts: pack.hosts.join(", "),
          type: pack.type === "universal" ? "Universal (free)" : pack.type,
          authority: pack.authority ?? "–",
          expires: pack.expires ? pack.expires.slice(0, 10) : "–"
        }));
      }
    },
    async created() {
      try {
        const response = await this.cf("get", "ssl");
        this.universal = response.universal;
        this.certificates = response.certificates;
        this.error = response.certificatesError;
      } catch (error) {
        this.error = error.message;
      } finally {
        this.loaded = true;
      }
    }
  };
  var _sfc_render$4 = function render() {
    var _vm = this, _c = _vm._self._c;
    return _c("div", [_c("settings-form", { attrs: { "group": "ssl", "label": "SSL/TLS settings" }, on: { "saved": function($event) {
      return _vm.$emit("changed");
    } } }), _c("k-section", { attrs: { "label": "Edge certificates" } }, [_vm.error ? _c("k-box", { attrs: { "theme": "negative", "icon": "alert", "text": _vm.error } }) : !_vm.loaded ? _c("k-empty", { attrs: { "icon": "loader" } }, [_vm._v("Loading…")]) : [_vm.universal === false ? _c("k-box", { attrs: { "theme": "notice", "icon": "alert", "text": "Universal SSL is off: visitors only get HTTPS from a certificate you uploaded or ordered." } }) : _vm._e(), _c("k-table", { attrs: { "columns": _vm.columns, "rows": _vm.rows, "index": false, "empty": "No edge certificates found." } })]], 2)], 1);
  };
  var _sfc_staticRenderFns$4 = [];
  _sfc_render$4._withStripped = true;
  var __component__$4 = /* @__PURE__ */ normalizeComponent(
    _sfc_main$4,
    _sfc_render$4,
    _sfc_staticRenderFns$4
  );
  __component__$4.options.__file = "/Volumes/2000/Sites/2026-CloudflarePlugin/src/components/tabs/SslTab.vue";
  const SslTab = __component__$4.exports;
  const _sfc_main$3 = {
    components: { SettingsForm },
    props: {
      status: { type: Object, required: true }
    }
  };
  var _sfc_render$3 = function render() {
    var _vm = this, _c = _vm._self._c;
    return _c("div", [_c("settings-form", { attrs: { "group": "speed", "label": "Speed" } }), _c("settings-form", { attrs: { "group": "network", "label": "Network" } })], 1);
  };
  var _sfc_staticRenderFns$3 = [];
  _sfc_render$3._withStripped = true;
  var __component__$3 = /* @__PURE__ */ normalizeComponent(
    _sfc_main$3,
    _sfc_render$3,
    _sfc_staticRenderFns$3
  );
  __component__$3.options.__file = "/Volumes/2000/Sites/2026-CloudflarePlugin/src/components/tabs/SpeedTab.vue";
  const SpeedTab = __component__$3.exports;
  const _sfc_main$2 = {
    mixins: [api],
    props: {
      status: { type: Object, required: true }
    },
    data() {
      return {
        records: null,
        types: [],
        writable: false,
        error: null,
        search: ""
      };
    },
    computed: {
      columns() {
        return {
          type: { label: "Type", type: "text", width: "1/12", mobile: true },
          name: { label: "Name", type: "text", width: "1/4", mobile: true },
          content: { label: "Content", type: "text" },
          proxy: { label: "Proxy", type: "text", width: "1/8" },
          ttlText: { label: "TTL", type: "text", width: "1/12" }
        };
      },
      rows() {
        const query = this.search.trim().toLowerCase();
        return (this.records ?? []).filter((record) => !query || [record.type, record.name, record.content, record.comment].some((value) => (value ?? "").toString().toLowerCase().includes(query))).map((record) => ({
          ...record,
          proxy: record.proxiable ? record.proxied ? "Proxied" : "DNS only" : "–",
          ttlText: record.ttl === 1 ? "Auto" : record.ttl + "s",
          content: record.priority !== null && record.priority !== void 0 ? `${record.priority} ${record.content}` : record.content
        }));
      },
      options() {
        return (row) => [
          { icon: "edit", text: "Edit", click: "edit" },
          ...row.proxiable ? [{ icon: "cloud", text: row.proxied ? "Turn proxy off" : "Turn proxy on", click: "proxy" }] : [],
          "-",
          { icon: "trash", text: "Delete", click: "delete" }
        ];
      }
    },
    created() {
      this.load();
    },
    methods: {
      async load() {
        try {
          const response = await this.cf("get", "dns");
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
        this.records = this.records.map((record) => record.id === updated.id ? updated : record);
      },
      async onOption(option, row) {
        const record = this.record(row.id);
        if (option === "edit") return this.edit(record);
        if (option === "proxy") return this.toggleProxy(record);
        if (option === "delete") return this.remove(record);
      },
      async edit(record = null) {
        const values = await this.form(
          {
            type: {
              label: "Type",
              type: "select",
              empty: false,
              width: "1/4",
              disabled: record !== null,
              options: this.types.map((type) => ({ value: type, text: type }))
            },
            name: { label: "Name", type: "text", required: true, width: "3/4", help: "@ for the domain itself" },
            content: { label: "Content", type: "text", required: true, font: "monospace" },
            priority: { label: "Priority", type: "number", width: "1/4", help: "MX and SRV only" },
            ttl: {
              label: "TTL",
              type: "select",
              empty: false,
              width: "1/4",
              options: [
                { value: "1", text: "Auto" },
                { value: "300", text: "5 min" },
                { value: "3600", text: "1 hour" },
                { value: "86400", text: "1 day" }
              ]
            },
            proxied: { label: "Proxied", type: "toggle", width: "1/2", text: ["DNS only", "Proxied"], help: "A, AAAA and CNAME only" },
            comment: { label: "Comment", type: "text" }
          },
          record ? { ...record, ttl: String(record.ttl) } : { type: "A", name: "", content: "", priority: null, ttl: "1", proxied: true, comment: "" },
          { button: record ? "Save" : "Add record", icon: record ? "check" : "add" }
        );
        if (!values) return;
        try {
          if (record) {
            const { type, ...changes } = values;
            if (!record.proxiable) delete changes.proxied;
            this.replace(await this.cf("patch", "dns/" + record.id, changes));
          } else {
            this.records = [await this.cf("post", "dns", values), ...this.records];
          }
          this.$panel.notification.success("Saved");
        } catch (error) {
          this.notifyError(error);
        }
      },
      async toggleProxy(record) {
        try {
          this.replace(await this.cf("patch", "dns/" + record.id, { proxied: !record.proxied }));
          this.$panel.notification.success(record.proxied ? "Proxy off: DNS only" : "Proxied through Cloudflare");
        } catch (error) {
          this.notifyError(error);
        }
      },
      async remove(record) {
        const ok = await this.confirm(
          `Delete the ${record.type} record for <strong>${this.$helper.string.escapeHTML(record.name)}</strong>? Anything that relies on it stops resolving.`,
          { button: "Delete", theme: "negative", icon: "trash" }
        );
        if (!ok) return;
        try {
          await this.cf("delete", "dns/" + record.id);
          this.records = this.records.filter((item) => item.id !== record.id);
          this.$panel.notification.success("Record deleted");
        } catch (error) {
          this.notifyError(error);
        }
      }
    }
  };
  var _sfc_render$2 = function render() {
    var _vm = this, _c = _vm._self._c;
    return _c("k-section", { attrs: { "label": "DNS records" }, scopedSlots: _vm._u([{ key: "options", fn: function() {
      return [_c("k-button-group", [_c("k-input", { staticClass: "cf-dns-search", attrs: { "type": "text", "icon": "search", "placeholder": "Filter…" }, model: { value: _vm.search, callback: function($$v) {
        _vm.search = $$v;
      }, expression: "search" } }), _vm.writable ? _c("k-button", { attrs: { "icon": "add", "size": "xs", "variant": "filled", "text": "Add record" }, on: { "click": function($event) {
        return _vm.edit();
      } } }) : _vm._e()], 1)];
    }, proxy: true }]) }, [_vm.error ? _c("k-box", { attrs: { "theme": "negative", "icon": "alert", "text": _vm.error } }) : !_vm.records ? _c("k-empty", { attrs: { "icon": "loader" } }, [_vm._v("Loading…")]) : [_c("k-table", { attrs: { "columns": _vm.columns, "rows": _vm.rows, "options": _vm.writable ? _vm.options : [], "index": false, "empty": "No DNS records match." }, on: { "option": _vm.onOption } }), _c("p", { staticClass: "cf-muted" }, [_vm._v(" Proxied records (orange cloud) go through Cloudflare's cache and firewall; DNS-only records point straight at the server. "), !_vm.writable ? [_vm._v(" Read-only here: set the plugin's "), _c("code", [_vm._v("dns")]), _vm._v(" option to "), _c("code", [_vm._v("write")]), _vm._v(", and give your role the "), _c("code", [_vm._v("dns")]), _vm._v(" permission, to change records. ")] : _vm._e()], 2)]], 2);
  };
  var _sfc_staticRenderFns$2 = [];
  _sfc_render$2._withStripped = true;
  var __component__$2 = /* @__PURE__ */ normalizeComponent(
    _sfc_main$2,
    _sfc_render$2,
    _sfc_staticRenderFns$2
  );
  __component__$2.options.__file = "/Volumes/2000/Sites/2026-CloudflarePlugin/src/components/tabs/DnsTab.vue";
  const DnsTab = __component__$2.exports;
  const _sfc_main$1 = {
    mixins: [api],
    props: {
      status: { type: Object, required: true }
    },
    data() {
      return { report: null, running: false };
    },
    computed: {
      can() {
        return this.status.permissions ?? {};
      }
    },
    methods: {
      async run() {
        this.running = true;
        try {
          this.report = await this.cf("post", "diagnostics");
          this.report.ok ? this.$panel.notification.success("Cloudflare token and zone work") : this.$panel.notification.error("Cloudflare check failed, see the report");
        } catch (error) {
          this.notifyError(error);
        } finally {
          this.running = false;
        }
      },
      state(line) {
        if (line.startsWith("✓")) return "pass";
        if (line.startsWith("✗")) return "fail";
        return "note";
      }
    }
  };
  var _sfc_render$1 = function render() {
    var _vm = this, _c = _vm._self._c;
    return _c("div", [_c("k-section", { attrs: { "label": "Connection check" }, scopedSlots: _vm._u([{ key: "options", fn: function() {
      return [_c("k-button", { attrs: { "disabled": !_vm.can.purge || _vm.running, "icon": "check", "size": "xs", "variant": "filled", "text": _vm.running ? "Checking…" : "Run check" }, on: { "click": _vm.run } })];
    }, proxy: true }]) }, [_c("div", { staticClass: "cf-card" }, [!_vm.report ? _c("p", { staticClass: "cf-muted" }, [_vm._v(" Checks the token is active, the Zone ID belongs to this site, and a purge really reaches Cloudflare's edge. It purges one small probe file only (the site's favicon unless the "), _c("code", [_vm._v("probe")]), _vm._v(" option says otherwise); nothing else in the cache is touched. ")]) : _c("ul", { staticClass: "cf-report" }, _vm._l(_vm.report.lines, function(line, index) {
      return _c("li", { key: index, attrs: { "data-state": _vm.state(line) } }, [_vm._v(" " + _vm._s(line) + " ")]);
    }), 0)])]), _c("k-section", { attrs: { "label": "From the command line" } }, [_c("div", { staticClass: "cf-card" }, [_c("pre", { staticClass: "cf-mono" }, [_vm._v("vendor/bin/kirby cloudflare-tools:check\nvendor/bin/kirby cloudflare-tools:purge\nvendor/bin/kirby cloudflare-tools:api GET 'zones/{zone}/settings'")]), _c("p", { staticClass: "cf-muted" }, [_vm._v(" The same checks as above, a full purge (Kirby's page cache first), and any Cloudflare API call for trying out features this panel doesn't cover yet. The first two also work as Janitor buttons. ")])])])], 1);
  };
  var _sfc_staticRenderFns$1 = [];
  _sfc_render$1._withStripped = true;
  var __component__$1 = /* @__PURE__ */ normalizeComponent(
    _sfc_main$1,
    _sfc_render$1,
    _sfc_staticRenderFns$1
  );
  __component__$1.options.__file = "/Volumes/2000/Sites/2026-CloudflarePlugin/src/components/tabs/DiagnosticsTab.vue";
  const DiagnosticsTab = __component__$1.exports;
  const TABS = {
    overview: OverviewTab,
    cache: CacheTab,
    security: SecurityTab,
    ssl: SslTab,
    speed: SpeedTab,
    dns: DnsTab,
    diagnostics: DiagnosticsTab
  };
  const _sfc_main = {
    components: { SetupNotice },
    mixins: [api],
    props: {
      label: { type: String, default: "Cloudflare" },
      tab: { type: String, default: "overview" },
      tabs: { type: Array, default: () => [] }
    },
    data() {
      return {
        status: null,
        error: null,
        loading: false
      };
    },
    computed: {
      tabButtons() {
        return this.tabs.map((tab) => ({
          ...tab,
          link: "/cloudflare-tools/" + tab.name
        }));
      },
      tabComponent() {
        return TABS[this.tab] ?? OverviewTab;
      },
      dashboardUrl() {
        var _a;
        const zone = (_a = this.status) == null ? void 0 : _a.zone;
        if (!(zone == null ? void 0 : zone.name)) return null;
        return zone.accountId ? `https://dash.cloudflare.com/${zone.accountId}/${zone.name}` : "https://dash.cloudflare.com/";
      }
    },
    created() {
      this.load();
    },
    methods: {
      async load() {
        this.loading = true;
        this.error = null;
        try {
          this.status = await this.cf("get", "status");
        } catch (error) {
          this.error = error.message;
        } finally {
          this.loading = false;
        }
      }
    }
  };
  var _sfc_render = function render() {
    var _vm = this, _c = _vm._self._c;
    return _c("k-panel-inside", { staticClass: "k-cloudflare-tools-view" }, [_c("k-header", { scopedSlots: _vm._u([{ key: "buttons", fn: function() {
      return [_c("k-button-group", [_vm.dashboardUrl ? _c("k-button", { attrs: { "link": _vm.dashboardUrl, "target": "_blank", "icon": "open", "size": "sm", "variant": "filled", "text": "Cloudflare dashboard" } }) : _vm._e(), _c("k-button", { attrs: { "disabled": _vm.loading, "icon": "refresh", "size": "sm", "variant": "filled", "title": "Reload" }, on: { "click": _vm.load } })], 1)];
    }, proxy: true }]) }, [_vm._v(" " + _vm._s(_vm.label) + " ")]), _c("k-tabs", { attrs: { "tab": _vm.tab, "tabs": _vm.tabButtons } }), _vm.error ? _c("k-box", { attrs: { "theme": "negative", "icon": "alert", "text": _vm.error } }) : !_vm.status ? _c("k-empty", { attrs: { "icon": "loader" } }, [_vm._v("Loading…")]) : !_vm.status.configured ? _c("setup-notice", { attrs: { "missing": _vm.status.missing } }) : _c(_vm.tabComponent, { key: _vm.tab, tag: "component", attrs: { "status": _vm.status }, on: { "changed": _vm.load } })], 1);
  };
  var _sfc_staticRenderFns = [];
  _sfc_render._withStripped = true;
  var __component__ = /* @__PURE__ */ normalizeComponent(
    _sfc_main,
    _sfc_render,
    _sfc_staticRenderFns
  );
  __component__.options.__file = "/Volumes/2000/Sites/2026-CloudflarePlugin/src/components/CloudflareView.vue";
  const CloudflareView = __component__.exports;
  window.panel.plugin("ianhobbs/cloudflare-tools", {
    components: {
      "k-cloudflare-tools-view": CloudflareView
    }
  });
})();
