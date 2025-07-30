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

    <!-- Settings Info Section -->
    <div class="k-ftp-backup-view-settings-info">
      <k-box theme="info">
        <h3>FTP Settings</h3>
        <p>FTP settings are managed through your site config file.</p>
        <p>Add the following to your <code>site/config/config.php</code> file:</p>
        
        <pre>
'tearoom1.ftp-backup' => [
    'ftpHost' => 'your-ftp-host.com',
    'ftpPort' => 21,
    'ftpUsername' => 'your-username',
    'ftpPassword' => 'your-password',
    'ftpDirectory' => '/backups',
    'ftpSsl' => false,
    'ftpPassive' => true,
    'backupDirectory' => 'content/.backups',
    'backupRetention' => 10,
    'deleteFromFtp' => true
]
        </pre>
        
        <p>For automatic backups, set up a cron job to run:</p>
        <pre>php /path/to/site/plugins/kirby-ftp-backup/run.php</pre>
      </k-box>
    </div>
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
