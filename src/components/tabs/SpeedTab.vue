<template>
  <div>
    <settings-form group="speed" label="Speed" />
    <settings-form group="network" label="Network" />
  </div>
</template>

<script>
import SettingsForm from '../SettingsForm.vue';

export default {
  components: { SettingsForm },
  props: {
    status: { type: Object, required: true },
  },
};
</script>
