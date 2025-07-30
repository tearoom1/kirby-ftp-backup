<template>
  <k-panel-inside class="k-ftp-backup-view">
    <!-- Stats Section -->
    <div class="k-ftp-backup-view-stats">
      <div class="k-ftp-backup-view-stats-card">
        <h3>Total Backups</h3>
        <p>{{ stats.count }}</p>
      </div>

      <div class="k-ftp-backup-view-stats-card">
        <h3>Total Size</h3>
        <p>{{ stats.formattedTotalSize }}</p>
      </div>

      <div class="k-ftp-backup-view-stats-card">
        <h3>Latest Backup</h3>
        <p v-if="stats.latestBackup">{{ stats.latestBackup.formattedDate }}</p>
        <p v-else>None</p>
        <div class="k-ftp-backup-view-stats-card-latest" v-if="stats.latestBackup">
          {{ stats.latestBackup.filename }}
        </div>
      </div>
    </div>

    <div class="k-ftp-backup-view-section">
      <div class="k-ftp-backup-view-actions">
        <k-button-group>
          <k-button
              icon="upload"
              @click="createBackup"
              :disabled="isLoading"
              :progress="isCreatingBackup"
          >
            Create Backup Now
          </k-button>
          <k-button
              icon="refresh"
              @click="loadBackups"
              :disabled="isLoading"
              :progress="isLoadingBackups"
          />
          <k-button
              icon="info"
              @click="showSettingsInfo"
          />
        </k-button-group>
      </div>
    </div>

    <!-- Backups List -->
    <div class="k-tab-content">
      <div v-if="isLoadingBackups" class="k-ftp-backup-view-loading">
        <k-loader />
      </div>
      <div v-else-if="backups.length === 0" class="k-ftp-backup-view-backup-list">
        <div class="k-ftp-backup-view-backup-list-empty">
          No backups available
        </div>
      </div>
      <k-collection
          v-else
          :items="backups"
          layout="list"
          @action="handleBackupAction"
      />
    </div>

    <!-- Settings Info Dialog -->
    <k-dialog
      ref="settingsDialog"
      size="large"
    >
      <div class="k-ftp-backup-dialog-content">
        <h2 class="k-ftp-backup-dialog-title">FTP Backup Settings</h2>
        
        <div class="k-ftp-backup-dialog-section">
          <h3>Configuration</h3>
          <p>FTP settings are managed through your site config file.</p>
          <p>Add the following to your <code>site/config/config.php</code> file:</p>
          
          <div class="k-ftp-backup-dialog-code">
<pre>'tearoom1.ftp-backup' => [
    'ftpHost' => 'your-ftp-host.com',
    'ftpPort' => 21,
    'ftpUsername' => 'your-username',
    'ftpPassword' => 'your-password',
    'ftpDirectory' => 'backups',
    'ftpSsl' => false,
    'ftpPassive' => true,
    'backupDirectory' => 'content/.backups',
    'backupRetention' => 10,
    'deleteFromFtp' => true
]</pre>
          </div>
        </div>
        
        <div class="k-ftp-backup-dialog-section">
          <h3>Automatic Backups</h3>
          <p>For automatic backups, set up a cron job to run:</p>
          <div class="k-ftp-backup-dialog-code">
            <pre>php /path/to/site/plugins/kirby-ftp-backup/run.php</pre>
          </div>
          <p class="k-ftp-backup-dialog-hint">Example crontab entry (daily at 2AM):</p>
          <div class="k-ftp-backup-dialog-code">
            <pre>0 2 * * * php /path/to/site/plugins/kirby-ftp-backup/run.php</pre>
          </div>
        </div>
      </div>

      <k-button-group slot="footer">
        <k-button icon="check" @click="$refs.settingsDialog.close()">
          Close
        </k-button>
      </k-button-group>
    </k-dialog>
  </k-panel-inside>
</template>

<script>
export default {
  props: {
    stats: Object
  },

  data() {
    return {
      isLoading: false,
      isCreatingBackup: false,
      isLoadingBackups: false,
      backups: []
    };
  },

  created() {
    this.loadBackups();
  },

  methods: {
    async loadBackups() {
      this.isLoadingBackups = true;

      try {
        const response = await this.$api.get('ftp-backup/backups');

        if (response.status === 'success' && response.data) {
          this.backups = response.data.map(backup => {
            return {
              id: backup.filename,
              text: backup.filename,
              info: new Date(backup.modified * 1000).toLocaleString() + ' - ' + this.formatSize(backup.size),
              link: backup.downloadUrl,
              icon: 'archive'
            };
          });
        } else {
          this.backups = [];
        }
      } catch (error) {
        window.panel.notification.error('Failed to load backups');
        this.backups = [];
      } finally {
        this.isLoadingBackups = false;
      }
    },

    async createBackup() {
      this.isCreatingBackup = true;

      try {
        const response = await this.$api.post('ftp-backup/create');

        if (response.status === 'success') {
          window.panel.notification.success(response.message);
          this.loadBackups();
        } else {
          window.panel.notification.error(response.message);
        }
      } catch (error) {
        window.panel.notification.error('Failed to create backup');
      } finally {
        this.isCreatingBackup = false;
      }
    },

    handleBackupAction(action, item) {
      if (action === 'download') {
        window.open('/' + item.link, '_blank');
      }
    },

    showSettingsInfo() {
      this.$refs.settingsDialog.open();
    },

    formatSize(bytes) {
      const units = ['B', 'KB', 'MB', 'GB', 'TB'];
      let size = bytes;
      let unitIndex = 0;

      while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex++;
      }

      return `${size.toFixed(2)} ${units[unitIndex]}`;
    }
  }
};
</script>
