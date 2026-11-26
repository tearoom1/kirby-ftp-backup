<template>
  <!-- Opened from a red tile through panel.dialog.open(): the full text of a problem, readable -->
  <k-dialog
    :visible="visible"
    :cancel-button="false"
    :submit-button="false"
    class="k-ftp-backup-problem-dialog"
    size="large"
    @cancel="$emit('cancel')"
  >
    <!-- Title with a red mark, so the dialog reads as a problem at a glance -->
    <div class="k-ftp-backup-problem-head">
      <span class="k-ftp-backup-problem-icon"><k-icon type="alert"/></span>
      <k-headline>{{ title }}</k-headline>
    </div>
    <p class="k-ftp-backup-problem-lead">{{ lead }}</p>

    <div v-if="endpoint" class="k-ftp-backup-connection">
      <span>{{ t('server.endpoint') }}</span>
      <code>{{ endpoint }}</code>
    </div>

    <p class="k-ftp-backup-problem-label">{{ t('problem.message') }}</p>
    <p class="k-ftp-backup-problem-message">{{ message }}</p>

    <p v-if="notice" class="k-ftp-backup-problem-notice">{{ notice }}</p>
    <p v-else-if="checked" class="k-ftp-backup-problem-checked">{{ checked }}</p>

    <!-- A custom footer slot replaces the core footer, so keep its wrapper for the padding -->
    <template #footer>
      <k-dialog-footer>
        <!-- actions on the left, closing on the right, so the row does not wrap -->
        <k-button-group>
          <k-button
            v-if="kind === 'remote'"
            icon="refresh"
            :progress="loading"
            :disabled="loading"
            @click="$emit('retry')"
          >
            {{ t('problem.retry') }}
          </k-button>
          <k-button v-if="kind === 'upload'" icon="cancel" @click="$emit('dismiss')">
            {{ t('problem.dismiss') }}
          </k-button>
        </k-button-group>
        <k-button-group>
          <k-button variant="filled" @click="$emit('cancel')">
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
    // 'remote': the server list could not be read, 'upload': a backup was not uploaded
    kind: String,
    title: String,
    lead: String,
    // The technical message from the server, shown in full
    message: String,
    // Formatted server address, if known
    endpoint: String,
    // "last checked …" line, if known
    checked: String,
    // a new attempt is running
    loading: Boolean,
    // result line of a new attempt that failed again
    notice: String,
    visible: Boolean
  },

  emits: ['cancel', 'retry', 'dismiss'],

  methods: {
    t(key, data) {
      return this.$t('tearoom1.ftp-backup.' + key, data);
    }
  }
};
</script>

<style>
.k-ftp-backup-problem-dialog .k-dialog-footer {
  display: flex;
  justify-content: space-between;
  gap: var(--spacing-2);
}

/* Running text with room between the lines, Kirby's dialog default is tight */
.k-ftp-backup-problem-head {
  display: flex;
  align-items: center;
  gap: var(--spacing-3);
}

.k-ftp-backup-problem-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 2rem;
  height: 2rem;
  border-radius: 50%;
  background: color-mix(in srgb, var(--color-red-500) 18%, transparent);
  color: light-dark(var(--color-red-700), var(--color-red-400));
}

.k-ftp-backup-problem-lead {
  margin-top: var(--spacing-2);
  line-height: 1.5;
}

.k-ftp-backup-problem-label {
  margin-top: var(--spacing-4);
  font-size: var(--text-sm);
  font-weight: var(--font-bold);
  color: var(--color-text-dimmed);
}

/* The message itself: normal text size, wrapped, easy to read and to copy */
.k-ftp-backup-problem-message {
  margin-top: var(--spacing-1);
  padding: var(--spacing-3);
  border-radius: var(--rounded);
  border: 1px solid color-mix(in srgb, var(--color-red-500) 35%, transparent);
  border-inline-start: 3px solid var(--color-red-500);
  background: color-mix(in srgb, var(--color-red-500) 8%, var(--theme-color-back));
  font-family: var(--font-mono);
  font-size: var(--text-sm);
  line-height: 1.5;
  overflow-wrap: anywhere;
  user-select: text;
}

.k-ftp-backup-problem-notice {
  margin-top: var(--spacing-2);
  font-size: var(--text-sm);
  color: light-dark(var(--color-red-700), var(--color-red-400));
}

.k-ftp-backup-problem-checked {
  margin-top: var(--spacing-2);
  font-size: var(--text-sm);
  color: var(--color-text-dimmed);
}
</style>
