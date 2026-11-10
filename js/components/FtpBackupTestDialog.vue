<template>
  <!-- Opened through panel.dialog.open(); the view updates the props while the test runs -->
  <k-dialog
    :visible="visible"
    :cancel-button="false"
    :submit-button="false"
    class="k-ftp-backup-stats-dialog"
    size="medium"
    @cancel="$emit('cancel')"
  >
    <k-headline>{{ t('test.title') }}</k-headline>

    <div v-if="endpoint" class="k-ftp-backup-connection">
      <span>{{ t('server.endpoint') }}</span>
      <code>{{ endpoint }}</code>
    </div>

    <div v-if="loading" class="k-ftp-backup-dialog-loading">
      <k-icon type="loader"/>
      <span>{{ t('test.running') }}</span>
    </div>

    <template v-else-if="result">
      <ul class="k-ftp-backup-test-steps">
        <li
          v-for="step in result.steps"
          :key="step.step"
          :class="step.ok ? 'is-ok' : 'is-failed'"
        >
          <k-icon :type="step.ok ? 'check' : 'cancel'"/>
          <div>
            <strong>{{ t('test.step.' + step.step) }}</strong>
            <p>{{ step.ok ? t('test.ok.' + step.step, step) : step.message }}</p>
          </div>
        </li>
      </ul>
      <k-box
        :theme="result.status === 'success' ? 'positive' : 'negative'"
        :text="result.status === 'success' ? t('test.success') : t('test.failed')"
      />
    </template>

    <!-- A custom footer slot replaces the core footer, so keep its wrapper for the padding -->
    <template #footer>
      <k-dialog-footer>
        <k-button-group>
          <k-button icon="check" @click="$emit('cancel')">
            {{ $t('close') }}
          </k-button>
        </k-button-group>
      </k-dialog-footer>
    </template>
  </k-dialog>
</template>

<script>
export default {
  props: {
    // Formatted server address of the tested connection
    endpoint: String,
    loading: Boolean,
    // Result of the test-connection route: status and steps
    result: Object,
    visible: Boolean
  },

  emits: ['cancel'],

  methods: {
    t(key, data) {
      return this.$t('tearoom1.ftp-backup.' + key, data);
    }
  }
};
</script>
