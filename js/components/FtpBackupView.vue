<template>
  <k-panel-inside class="k-ftp-backup-view">
    <!-- Warning Panel for FTP Errors -->
    <div v-if="ftpWarning.show" class="k-ftp-backup-view-warning">
      <k-box theme="negative" class="k-ftp-backup-view-warning-box">
        <k-icon type="alert"/>
        <span>{{ ftpWarning.message }}</span>
        <k-button icon="settings" @click="showSettingsInfo"/>
        <k-button icon="cancel" @click="dismissWarning"/>
      </k-box>
    </div>

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
            :disabled="isLoading || isCreatingBackup"
            :progress="isCreatingBackup"
          >
            Create Backup Now
          </k-button>
          <k-button
            icon="refresh"
            @click="loadBackups"
            :disabled="isLoading || isCreatingBackup"
            :progress="isLoadingBackups"
          />
          <k-button
            icon="info"
            @click="showSettingsInfo"
          />
          <k-button
            icon="server"
            @click="showFtpServerStats"
            :disabled="isLoading || isCreatingBackup || isLoadingFtpStats"
            :progress="isLoadingFtpStats"
            title="Show FTP Server Stats"
          />
        </k-button-group>
      </div>
    </div>

    <!-- Backups List -->
    <div class="k-tab-content">
      <div v-if="isLoadingBackups" class="k-ftp-backup-view-loading">
        <k-loader/>
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
      >
        <template slot="options" slot-scope="{ item }">
          <k-button icon="download" @click="downloadBackup(item)"/>
        </template>
      </k-collection>
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
<pre>'tearoom1.kirby-ftp-backup' => [
    'ftpProtocol' => 'ftp', // ftp, ftps or sftp
    'ftpHost' => 'your-ftp-host.com',
    'ftpPort' => 21,
    'ftpUsername' => 'your-username',
    'ftpPassword' => 'your-password',
    'ftpDirectory' => 'backups',
    'ftpPassive' => true,
    'ftpPrivateKey' => '', // for sftp
    'ftpPassphrase' => '' // for sftp
]</pre>
          </div>
          Find more about those options in the Readme.md file
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

    <!-- FTP Server Stats Dialog -->
    <k-dialog ref="ftpStatsDialog" :button="'close'" size="large" theme="info">
      <k-headline>FTP Server Stats</k-headline>

      <div v-if="isLoadingFtpStats" class="k-ftp-backup-dialog-loading">
        <k-icon type="loader" class="k-ftp-backup-spinner" />
        <span>Loading FTP server stats...</span>
      </div>

      <div v-else-if="ftpStatsError" class="k-ftp-backup-dialog-error">
        <k-box theme="negative">
          {{ ftpStatsError }}
        </k-box>
      </div>

      <div v-else-if="ftpStats" class="k-ftp-backup-view-server-stats">
        <div class="k-ftp-backup-view-stats">
          <div class="k-ftp-backup-view-stats-card">
            <h3>Files on Server</h3>
            <p>{{ ftpStats.count }}</p>
          </div>

          <div class="k-ftp-backup-view-stats-card">
            <h3>Total Size</h3>
            <p>{{ ftpStats.formattedTotalSize }}</p>
          </div>

          <div class="k-ftp-backup-view-stats-card">
            <h3>Latest Backup</h3>
            <p>{{ ftpStats.latestModified }}</p>
          </div>
        </div>

        <div class="k-ftp-backup-dialog-section">
          <h3>Files on FTP Server</h3>
          <div v-if="ftpStats.files && ftpStats.files.length > 0">
            <table class="k-ftp-backup-files-table">
              <thead>
              <tr>
                <th>Filename</th>
                <th>Size</th>
                <th>Date</th>
              </tr>
              </thead>
              <tbody>
              <tr v-for="file in ftpStats.files" :key="file.filename">
                <td>{{ file.filename }}</td>
                <td>{{ file.formattedSize }}</td>
                <td>{{ file.formattedDate }}</td>
              </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="k-ftp-backup-view-backup-list-empty">
            No backups available on FTP server
          </div>
        </div>
      </div>
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
      isLoadingFtpStats: false,
      backups: [],
      ftpWarning: {
        show: false,
        message: '',
        isPersistent: false
      },
      ftpStats: null,
      ftpStatsError: null
    };
  },

  created() {
    this.loadBackups();
    this.checkFtpSettings();
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
              url: backup.downloadUrl,
              icon: 'archive'
            };
          });
        } else {
          this.backups = [];
        }

        if (response.status === 'success' && response.stats) {
          this.stats = response.stats;
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

          // Check if FTP upload failed (but only if FTP is enabled)
          if (response.data && response.data.ftpResult && !response.data.ftpResult.uploaded && !response.data.ftpResult.disabled) {
            this.showFtpWarning(response.data.ftpResult.message || 'FTP upload failed', true);
          } else {
            this.dismissWarning();
          }
        } else {
          window.panel.notification.error(response.message);

          // Show warning if the error appears to be FTP-related
          if (response.message && response.message.toLowerCase().includes('ftp')) {
            this.showFtpWarning(response.message, true);
          }
        }
      } catch (error) {
        window.panel.notification.error('Failed to create backup');
        this.showFtpWarning('Error: Failed to create backup. FTP connection might have failed.', true);
      } finally {
        this.isCreatingBackup = false;
      }
    },

    showSettingsInfo() {
      this.$refs.settingsDialog.open();
    },

    downloadBackup(item) {
      window.open(item.url, '_blank');
    },

    // FTP Server Stats methods
    showFtpServerStats() {
      this.$refs.ftpStatsDialog.open();
      this.loadFtpServerStats();
    },

    async loadFtpServerStats() {
      this.isLoadingFtpStats = true;
      this.ftpStatsError = null;

      try {
        const response = await this.$api.get('ftp-backup/ftp-stats');

        if (response.status === 'success' && response.data) {
          this.ftpStats = response.data;
        } else {
          this.ftpStatsError = response.message || 'Failed to load FTP server stats';
          this.ftpStats = null;
        }
      } catch (error) {
        this.ftpStatsError = 'Failed to connect to FTP server';
        this.ftpStats = null;
      } finally {
        this.isLoadingFtpStats = false;
      }
    },

    // FTP Warning Panel Methods
    showFtpWarning(message, isPersistent = false) {
      this.ftpWarning = {
        show: true,
        message: message,
        isPersistent: isPersistent
      };

      // Store in sessionStorage for persistence during the session
      if (isPersistent) {
        sessionStorage.setItem('ftpBackupWarning', JSON.stringify(this.ftpWarning));
      }
    },

    dismissWarning() {
      this.ftpWarning.show = false;
      sessionStorage.removeItem('ftpBackupWarning');
    },

    checkFtpSettings() {
      // Check if there's a persisted warning message
      const savedWarning = sessionStorage.getItem('ftpBackupWarning');
      if (savedWarning) {
        try {
          this.ftpWarning = JSON.parse(savedWarning);
        } catch (e) {
          // Invalid JSON, clear it
          sessionStorage.removeItem('ftpBackupWarning');
        }
      }

      // Always check if FTP settings are configured
      this.$api.get('ftp-backup/settings-status')
        .then(response => {
          if (response.status === 'success') {
            // Check if FTP is explicitly disabled
            if (response.data.ftpEnabled === false) {
              this.showFtpWarning('FTP upload is disabled. Backups will be created locally only.', true);
            } 
            // Check if FTP is not configured
            else if (!response.data.configured) {
              this.showFtpWarning('FTP settings are not configured. Backups will be created locally only.', true);
            } 
            // FTP is enabled and configured
            else {
              this.dismissWarning();
            }
          }
        })
        .catch(() => {
          // Silent failure, we don't want to show errors for this check
        });
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
