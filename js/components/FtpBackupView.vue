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
        <p>{{ localStats.count }}</p>
      </div>

      <div class="k-ftp-backup-view-stats-card">
        <h3>Total Size</h3>
        <p>{{ localStats.formattedTotalSize }}</p>
      </div>

      <div class="k-ftp-backup-view-stats-card">
        <h3>Latest Backup</h3>
        <p v-if="localStats.latestBackup">{{ localStats.latestBackup.formattedDate }}</p>
        <p v-else>None</p>
        <div class="k-ftp-backup-view-stats-card-latest" v-if="localStats.latestBackup">
          {{ localStats.latestBackup.filename }}
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
            v-if="isCreatingBackup"
            icon="cancel"
            theme="negative"
            @click="cancelBackup"
            :disabled="isCancelling"
          >
            Cancel
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

      <!-- Progress bar -->
      <div v-if="isCreatingBackup" class="k-ftp-backup-progress">
        <div class="k-ftp-backup-progress-bar">
          <div
            v-if="isIndeterminate"
            class="k-ftp-backup-progress-indeterminate"
          ></div>
          <div
            v-else
            class="k-ftp-backup-progress-fill"
            :style="{ width: progressPercent + '%' }"
          ></div>
        </div>
        <div class="k-ftp-backup-progress-label">
          {{ progressLabel }}
        </div>
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
        <template #options="{ item }">
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

      <template #footer>
        <k-button-group>
          <k-button icon="check" @click="$refs.settingsDialog.close()">
            Close
          </k-button>
        </k-button-group>
      </template>
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
      isCancelling: false,
      isLoadingBackups: false,
      isLoadingFtpStats: false,
      localStats: this.stats || {},
      backups: [],
      ftpWarning: {
        show: false,
        message: '',
        isPersistent: false
      },
      ftpStats: null,
      ftpStatsError: null,
      // Progress tracking
      currentJobId: null,
      progressPollTimer: null,
      progressPhase: null,
      progressCurrent: 0,
      progressTotal: 0,
      progressMessage: ''
    };
  },

  computed: {
    progressPercent() {
      if (!this.progressTotal) return 0;
      return Math.min(100, Math.round((this.progressCurrent / this.progressTotal) * 100));
    },
    isIndeterminate() {
      return this.progressPhase === 'zip';
    },
    progressLabel() {
      const phase = this.progressPhase;
      if (!phase || phase === 'starting') return 'Preparing…';
      if (phase === 'zip') return this.progressMessage || 'Compressing archive…';
      if (phase === 'upload') {
        return this.progressTotal && this.progressCurrent
          ? `Uploading… ${this.formatSize(this.progressCurrent)} / ${this.formatSize(this.progressTotal)} (${this.progressPercent}%)`
          : 'Uploading to FTP server…';
      }
      if (phase === 'cleanup') return 'Cleaning up old backups…';
      if (phase === 'done') return this.progressMessage || 'Done';
      if (phase === 'cancelled') return 'Cancelled';
      if (phase === 'error') return 'Error: ' + (this.progressMessage || 'Unknown error');
      return this.progressMessage || '';
    }
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
          this.localStats = response.stats;
        }
      } catch (error) {
        window.panel.notification.error('Failed to load backups');
        this.backups = [];
      } finally {
        this.isLoadingBackups = false;
      }
    },

    generateJobId() {
      if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
      }
      return Date.now().toString(36) + Math.random().toString(36).slice(2);
    },

    startProgressPolling() {
      this.stopProgressPolling();
      this.progressPollTimer = setInterval(async () => {
        if (!this.currentJobId) return;
        try {
          const response = await this.$api.get('ftp-backup/progress/' + this.currentJobId);
          const d = response && response.data;
          // Only accept data that looks valid — ignore corrupted/race-condition reads
          if (d && d.phase && d.phase !== 'unknown') {
            this.progressPhase = d.phase;
            this.progressCurrent = d.current || 0;
            this.progressTotal = d.total || 0;
            this.progressMessage = d.message || '';
          }
        } catch (e) {
          // silent — polling can fail without disrupting the backup
        }
      }, 800);
    },

    stopProgressPolling() {
      if (this.progressPollTimer) {
        clearInterval(this.progressPollTimer);
        this.progressPollTimer = null;
      }
    },

    async cancelBackup() {
      if (!this.currentJobId || this.isCancelling) return;
      this.isCancelling = true;
      try {
        await this.$api.post('ftp-backup/cancel', { jobId: this.currentJobId });
        this.progressMessage = 'Cancellation requested…';
      } catch (e) {
        window.panel.notification.error('Failed to send cancel signal');
        this.isCancelling = false;
      }
    },

    async createBackup() {
      this.isCreatingBackup = true;
      this.isCancelling = false;
      this.currentJobId = this.generateJobId();
      this.progressPhase = 'starting';
      this.progressCurrent = 0;
      this.progressTotal = 0;
      this.progressMessage = '';
      this.startProgressPolling();

      try {
        const response = await this.$api.post('ftp-backup/create', { jobId: this.currentJobId });

        if (response.status === 'cancelled') {
          window.panel.notification.info('Backup was cancelled');
          return;
        }

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
          // Show the actual error message from the backend
          const errorMessage = response.message || 'Failed to create backup';
          window.panel.notification.error(errorMessage);

          // Show warning if the error appears to be FTP-related
          if (errorMessage.toLowerCase().includes('ftp') || errorMessage.toLowerCase().includes('sftp')) {
            this.showFtpWarning(errorMessage, true);
          }
        }
      } catch (error) {
        // Network or API error
        const errorMessage = error.message || 'Failed to create backup';
        window.panel.notification.error(errorMessage);
        console.error('Backup creation error:', error);
        this.showFtpWarning('Error: ' + errorMessage, true);
      } finally {
        this.stopProgressPolling();
        this.isCreatingBackup = false;
        this.isCancelling = false;
        this.currentJobId = null;
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

<style>
.k-ftp-backup-progress {
  margin-top: 1rem;
}
.k-ftp-backup-progress-bar {
  height: 6px;
  background: var(--color-border);
  border-radius: 3px;
  overflow: hidden;
}
.k-ftp-backup-progress-fill {
  height: 100%;
  background: var(--color-focus);
  border-radius: 3px;
  transition: width 0.4s ease;
}
.k-ftp-backup-progress-indeterminate {
  height: 100%;
  width: 40%;
  background: var(--color-focus);
  border-radius: 3px;
  animation: k-ftp-backup-slide 1.4s ease-in-out infinite;
}
@keyframes k-ftp-backup-slide {
  0%   { transform: translateX(-100%); }
  100% { transform: translateX(250%); }
}
.k-ftp-backup-progress-label {
  margin-top: 0.5rem;
  font-size: var(--text-sm);
  color: var(--color-text-light);
}
</style>
