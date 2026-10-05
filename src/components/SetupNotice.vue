<template>
  <k-section label="Connect Cloudflare">
    <k-box theme="notice" icon="key">
      <k-text>
        <p>
          Missing: <strong>{{ missing.join(' and ') }}</strong>.
        </p>
        <p>
          The plugin needs an <strong>API token</strong> and the
          <strong>Zone ID</strong> of your domain. Both stay on the server: put
          them in a <code>.env</code> file outside the web root and load it from
          <code>site/config/config.php</code>:
        </p>
        <pre class="cf-mono">CLOUDFLARE_API_TOKEN=…
CLOUDFLARE_ZONE_ID=…</pre>
        <p>
          The Zone ID is on your domain's <em>Overview</em> page in the
          Cloudflare dashboard. Create the token under
          <em>My Profile › API Tokens</em>. See the plugin README,
          "Credentials", for the permissions each feature needs.
        </p>
      </k-text>
    </k-box>
  </k-section>
</template>

<script>
export default {
  props: {
    missing: { type: Array, default: () => [] },
  },
};
</script>
