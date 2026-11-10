<template>
  <!-- Setup help for admins, opened through panel.dialog.open() -->
  <k-dialog
    :visible="visible"
    :cancel-button="false"
    :submit-button="false"
    size="large"
    @cancel="$emit('cancel')"
  >
    <div class="k-ftp-backup-dialog-content">
      <h2 class="k-ftp-backup-dialog-title">{{ t('setup.title') }}</h2>

      <div class="k-ftp-backup-dialog-section">
        <p>{{ t('setup.config') }}</p>

        <div class="k-ftp-backup-dialog-code">
<pre>'tearoom1.kirby-ftp-backup' => [
    'ftpProtocol' => 'sftp', // ftp, ftps or sftp
    'ftpHost' => 'your-ftp-host.com',
    'ftpUsername' => 'your-username',
    'ftpPassword' => 'your-password',
    'ftpPrivateKey' => '/path/to/key', // sftp: instead of a password
    'ftpDirectory' => 'backups',
    'retentionStrategy' => 'tiered',
    'notifyEmail' => 'you@example.com',
    'maxBackupAge' => 36, // hours
]</pre>
        </div>
        <p class="k-ftp-backup-dialog-hint">{{ t('setup.readme') }}</p>
      </div>

      <div class="k-ftp-backup-dialog-section">
        <p>{{ t('setup.cron') }}</p>
        <div class="k-ftp-backup-dialog-code">
          <pre>0 2 * * * php /path/to/site/plugins/kirby-ftp-backup/run.php</pre>
        </div>
      </div>
    </div>

    <template #footer>
      <k-button-group>
        <k-button icon="check" @click="$emit('cancel')">
          {{ $t('close') }}
        </k-button>
      </k-button-group>
    </template>
  </k-dialog>
</template>

<script>
export default {
  props: {
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
