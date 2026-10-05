/**
 * Kirby Panel — Cloudflare Tools area
 *
 * Compiled by kirbyup into index.js / index.css in the plugin root, which
 * Kirby loads automatically. Every Cloudflare call goes through the plugin's
 * own API routes (/api/cloudflare-tools/…): the token stays on the server.
 */
import CloudflareView from './components/CloudflareView.vue';

window.panel.plugin('ianhobbs/cloudflare-tools', {
  components: {
    'k-cloudflare-tools-view': CloudflareView,
  },
});
