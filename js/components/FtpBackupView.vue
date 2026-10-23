<template>
  <k-panel-inside class="k-ftp-backup-view">
    <k-header>
      FTP Backup
      <template #buttons>
        <k-button
          icon="upload"
          size="sm"
          variant="filled"
          theme="positive"
          :disabled="isLoading || isCreatingBackup"
          :progress="isCreatingBackup"
          :text="t('action.create')"
          @click="createBackup"
        />
        <k-button
          v-if="isCreatingBackup"
          icon="cancel"
          size="sm"
          variant="filled"
          theme="negative"
          :disabled="isCancelling"
          :text="t('action.cancel')"
          @click="cancelBackup"
        />
        <k-button
          class="k-ftp-backup-view-sponsor-button"
          icon="heart"
          size="sm"
          variant="filled"
          :responsive="true"
          link="https://www.tearoom.one/en/kirby-plugins/support"
          target="_blank"
          :text="t('sponsor.label')"
        />
        <k-options-dropdown
          icon="dots"
          size="sm"
          variant="filled"
          :options="headerOptions"
        />
      </template>
    </k-header>

    <!-- Progress of a running backup -->
    <k-section v-if="isCreatingBackup" class="k-ftp-backup-progress">
      <div class="k-ftp-backup-progress-bar">
        <div v-if="isIndeterminate" class="k-ftp-backup-progress-indeterminate"></div>
        <div v-else class="k-ftp-backup-progress-fill" :style="{ width: progressPercent + '%' }"></div>
      </div>
      <p class="k-ftp-backup-progress-label">{{ progressLabel }}</p>
    </k-section>

    <!-- Result of the last run and FTP problems -->
    <k-section v-if="statusMessages.length || ftpWarning.show" class="k-ftp-backup-view-status">
      <k-box
        v-for="status in statusMessages"
        :key="status.key"
        :theme="status.theme"
        :icon="status.icon"
        :text="status.text"
      />
      <k-box v-if="ftpWarning.show" theme="negative" class="k-ftp-backup-view-warning-box">
        <k-icon type="alert"/>
        <span>{{ ftpWarning.message }}</span>
        <k-button v-if="isAdmin" icon="settings" :title="t('action.setup')" @click="showSettingsInfo"/>
        <k-button icon="cancel" :title="$t('close')" @click="dismissWarning"/>
      </k-box>
    </k-section>

    <!-- Overview: Kirby's stat tiles, with a second info line where needed -->
    <k-section>
      <dl class="k-stats k-ftp-backup-view-overview" data-size="medium">
        <div class="k-stat">
          <dt class="k-stat-label">{{ t('stats.count') }}</dt>
          <dd class="k-stat-value">{{ listItems.length }}</dd>
          <dd class="k-stat-info">{{ countInfo }}</dd>
        </div>

        <div class="k-stat">
          <dt class="k-stat-label">{{ t('stats.size') }}</dt>
          <dd class="k-stat-value">{{ t('stats.sizeLocal', {size: formatSize(localTotalSize)}) }}</dd>
          <dd v-if="ftpConfigured" class="k-stat-info">{{ t('stats.sizeRemote', {size: remoteTotalSize}) }}</dd>
        </div>

        <div class="k-stat" :data-theme="isStale ? 'notice' : null">
          <dt class="k-stat-label">
            <k-icon v-if="isStale" type="alert"/>
            {{ t('stats.latest') }}
          </dt>
          <dd class="k-stat-value">{{ latestBackup ? formatDate(latestBackup.modified) : t('stats.none') }}</dd>
          <dd v-if="isStale" class="k-stat-info">
            {{ latestBackup ? t('stats.stale', {hours: maxBackupAge}) : t('stats.staleNone') }}
          </dd>
          <dd v-if="latestBackup" class="k-stat-info k-ftp-backup-view-overview-file" :title="latestBackup.filename">
            {{ latestBackup.filename }}
          </dd>
          <dd v-if="latestBackup && latestLocation" class="k-stat-info">{{ latestLocation }}</dd>
        </div>
      </dl>
    </k-section>

    <!-- Backups: local backups and the copies on the server in one list -->
    <k-section :label="t('list.title')" :buttons="sectionButtons">
      <k-loader v-if="isLoadingBackups && !listItems.length"/>
      <k-empty v-else-if="listItems.length === 0" icon="archive" :text="t('list.empty')"/>
      <div v-else class="k-ftp-backup-table">
        <table>
          <thead>
          <tr>
            <th>{{ t('list.created') }}</th>
            <th class="is-right">{{ t('list.size') }}</th>
            <th class="is-center">{{ t('list.colLocal') }}</th>
            <th v-if="ftpConfigured" class="is-center">{{ t('list.colServer') }}</th>
            <th>{{ t('list.colNote') }}</th>
            <th>{{ t('list.file') }}</th>
            <th><span class="k-ftp-backup-sr">{{ t('list.actions') }}</span></th>
          </tr>
          </thead>
          <tbody>
          <tr v-for="backup in listItems" :key="backup.filename">
            <td class="k-ftp-backup-table-date">
              <strong>{{ formatDay(backup.modified) }}</strong>
              <span>{{ formatTime(backup.modified) }}</span>
            </td>
            <td class="k-ftp-backup-table-size is-right">{{ formatSize(backup.size || 0) }}</td>
            <td class="k-ftp-backup-table-flag is-center">
              <k-icon v-if="backup.local" type="check" class="is-yes" :title="t('list.colLocal')"/>
              <span v-else class="is-no">–</span>
            </td>
            <td v-if="ftpConfigured" class="k-ftp-backup-table-flag is-center">
              <span v-if="remoteFiles === null" class="is-no" :title="isLoadingRemote ? t('list.loadingServer') : ''">?</span>
              <k-icon v-else-if="backup.remote" type="check" class="is-yes" :title="t('list.colServer')"/>
              <span v-else class="is-no">–</span>
            </td>
            <td class="k-ftp-backup-table-location">{{ locationLabel(backup) }}</td>
            <td class="k-ftp-backup-table-notes">
              <span v-if="backup.protected" class="k-ftp-backup-tag is-protected">{{ t('list.protected') }}</span>
              <span v-if="backup.deleteNext" class="k-ftp-backup-tag is-delete">{{ t('list.deleteNext') }}</span>
            </td>
            <td class="k-ftp-backup-table-file" :title="backup.filename">{{ backup.filename }}</td>
            <td class="k-ftp-backup-table-options">
              <k-options-dropdown :options="itemOptions(backup)"/>
            </td>
          </tr>
          </tbody>
        </table>
      </div>
      <p v-if="isLoadingRemote" class="k-ftp-backup-view-list-note">
        {{ t('list.loadingServer') }}
      </p>
      <p v-else-if="remoteError" class="k-ftp-backup-view-list-note">
        {{ t('list.serverError', { message: remoteError }) }}
      </p>
    </k-section>

    <!-- Connection Test Dialog -->
    <k-dialog
      ref="testDialog"
      class="k-ftp-backup-stats-dialog"
      size="medium"
    >
      <k-headline>{{ t('test.title') }}</k-headline>

      <div v-if="connectionTest && connectionTest.connection" class="k-ftp-backup-connection">
        <span>{{ t('server.endpoint') }}</span>
        <code>{{ formatConnection(connectionTest.connection) }}</code>
      </div>

      <div v-if="isTestingConnection" class="k-ftp-backup-dialog-loading">
        <k-loader/>
        <span>{{ t('test.running') }}</span>
      </div>

      <template v-else-if="connectionTest">
        <ul class="k-ftp-backup-test-steps">
          <li
            v-for="step in connectionTest.steps"
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
          :theme="connectionTest.status === 'success' ? 'positive' : 'negative'"
          :text="connectionTest.status === 'success' ? t('test.success') : t('test.failed')"
        />
      </template>

      <template #footer>
        <k-button-group>
          <k-button icon="check" @click="$refs.testDialog.close()">
            {{ $t('close') }}
          </k-button>
        </k-button-group>
      </template>
    </k-dialog>

    <!-- Settings Info Dialog -->
    <k-dialog
      ref="settingsDialog"
      size="large"
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
          <k-button icon="check" @click="$refs.settingsDialog.close()">
            {{ $t('close') }}
          </k-button>
        </k-button-group>
      </template>
    </k-dialog>

    <!-- FTP Server Stats Dialog -->
    <k-dialog
      ref="ftpStatsDialog"
      class="k-ftp-backup-stats-dialog"
      size="large"
    >
      <k-headline>{{ t('server.title') }}</k-headline>

      <div v-if="isLoadingFtpStats" class="k-ftp-backup-dialog-loading">
        <k-loader/>
        <span>{{ t('server.loading') }}</span>
      </div>

      <div v-else-if="ftpStatsError" class="k-ftp-backup-dialog-error">
        <div v-if="ftpStatsErrorConnection" class="k-ftp-backup-connection">
          <span>{{ t('server.endpoint') }}</span>
          <code>{{ ftpStatsErrorConnection }}</code>
        </div>
        <k-box theme="negative">
          {{ ftpStatsError }}
        </k-box>
      </div>

      <div v-else-if="ftpStats" class="k-ftp-backup-view-server-stats">
        <div v-if="ftpStats.connection" class="k-ftp-backup-connection">
          <span>{{ t('server.endpoint') }}</span>
          <code>{{ ftpStatsConnection }}</code>
        </div>

        <div class="k-ftp-backup-view-stats">
          <div class="k-ftp-backup-view-stats-card">
            <h3>{{ t('server.files') }}</h3>
            <p>{{ ftpStats.count }}</p>
          </div>

          <div class="k-ftp-backup-view-stats-card">
            <h3>{{ t('stats.size') }}</h3>
            <p>{{ ftpStats.formattedTotalSize }}</p>
          </div>

          <div class="k-ftp-backup-view-stats-card">
            <h3>{{ t('stats.latest') }}</h3>
            <p>{{ ftpStats.latestModified }}</p>
          </div>
        </div>

        <div class="k-ftp-backup-dialog-section">
          <h3 class="k-ftp-backup-files-title">{{ t('server.files') }}</h3>
          <div v-if="ftpStats.files && ftpStats.files.length > 0">
            <table class="k-ftp-backup-files-table">
              <thead>
              <tr>
                <th>{{ t('server.filename') }}</th>
                <th>{{ t('server.size') }}</th>
                <th>{{ t('server.date') }}</th>
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
            {{ t('server.empty') }}
          </div>
        </div>
      </div>

      <template #footer>
        <k-button-group>
          <k-button icon="check" @click="$refs.ftpStatsDialog.close()">
            {{ $t('close') }}
          </k-button>
        </k-button-group>
      </template>
    </k-dialog>
  </k-panel-inside>
</template>

<script>
export default {
  props: {
    stats: Object,
    isAdmin: Boolean
  },

  data() {
    return {
      isLoading: false,
      isCreatingBackup: false,
      isCancelling: false,
      // true until the first list arrives, so the empty state does not flash
      isLoadingBackups: true,
      isLoadingFtpStats: false,
      isLoadingRemote: false,
      isTestingConnection: false,
      localStats: this.stats || {},
      localBackups: [],
      remoteFiles: null,
      remoteError: null,
      health: null,
      ftpConfigured: false,
      connectionTest: null,
      ftpWarning: {
        show: false,
        message: '',
        isPersistent: false
      },
      ftpStats: null,
      ftpStatsError: null,
      ftpStatsErrorConnection: '',
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
    locale() {
      const translation = window.panel && window.panel.translation;
      return (translation && translation.code) || undefined;
    },

    // Local backups and server copies merged by filename, newest first
    listItems() {
      const byName = {};

      for (const backup of this.localBackups) {
        byName[backup.filename] = {...backup, local: true, remote: false};
      }
      for (const file of this.remoteFiles || []) {
        const entry = byName[file.filename] || {
          filename: file.filename,
          size: file.size,
          modified: file.modified,
          local: false,
          protected: file.protected
        };
        entry.remote = true;
        entry.deleteNext = entry.deleteNext || file.deleteNext;
        byName[file.filename] = entry;
      }

      return Object.values(byName)
        .sort((a, b) => (b.modified - a.modified) || b.filename.localeCompare(a.filename));
    },

    statusMessages() {
      const messages = [];
      const health = this.health;
      if (!health) {
        return messages;
      }

      const run = health.lastRun;
      if (run) {
        const data = {date: this.formatDate(run.time), message: run.message || ''};
        const themes = {success: 'positive', warning: 'notice', error: 'negative', cancelled: 'info'};
        let key = 'lastRun.' + run.status;
        if (run.status === 'success') {
          key = run.uploaded ? 'lastRun.uploaded' : 'lastRun.local';
        }
        messages.push({
          key: 'lastRun',
          theme: themes[run.status] || 'info',
          icon: run.status === 'success' ? 'check' : 'alert',
          text: this.t(key, data)
        });
      }

      return messages;
    },

    // Header "…" menu; the setup help is only for admins
    headerOptions() {
      const busy = this.isLoading || this.isCreatingBackup;
      const options = [
        {icon: 'check', text: this.t('action.test'), click: () => this.testConnection(), disabled: this.isCreatingBackup || this.isTestingConnection},
        {icon: 'server', text: this.t('action.server'), click: () => this.showFtpServerStats(), disabled: busy || this.isLoadingFtpStats}
      ];
      if (this.isAdmin) {
        options.push({icon: 'info', text: this.t('action.setup'), click: () => this.showSettingsInfo()});
      }
      return options;
    },

    sectionButtons() {
      return [{
        icon: 'refresh',
        title: this.t('action.refresh'),
        disabled: this.isLoadingBackups || this.isLoadingRemote || this.isCreatingBackup,
        click: () => this.loadBackups()
      }];
    },

    // The server values stay "–" until the server listing has arrived
    remoteKnown() {
      return this.ftpConfigured && this.remoteFiles !== null;
    },

    countInfo() {
      const local = this.localBackups.length;
      if (!this.ftpConfigured) {
        return this.t('stats.countLocal', {local});
      }
      return this.t('stats.countInfo', {local, remote: this.remoteKnown ? this.remoteFiles.length : '–'});
    },

    localTotalSize() {
      return this.localBackups.reduce((sum, backup) => sum + (backup.size || 0), 0);
    },

    remoteTotalSize() {
      return this.remoteKnown
        ? this.formatSize(this.remoteFiles.reduce((sum, file) => sum + (file.size || 0), 0))
        : '–';
    },

    latestBackup() {
      return this.listItems[0] || null;
    },

    latestLocation() {
      if (!this.ftpConfigured) {
        return '';
      }
      if (this.remoteFiles === null) {
        return this.isLoadingRemote ? this.t('list.loadingServer') : '';
      }
      return this.locationLabel(this.latestBackup);
    },

    maxBackupAge() {
      return (this.health && this.health.maxBackupAge) || 0;
    },

    // Same rule as the server check (maxBackupAge in hours, 0 = off), applied to
    // the newest backup in the list, so a copy that only exists on the server counts too
    isStale() {
      if (!this.maxBackupAge || this.isLoadingBackups && !this.listItems.length) {
        return false;
      }
      const latest = this.latestBackup;
      return !latest || latest.modified < Date.now() / 1000 - this.maxBackupAge * 3600;
    },

    progressPercent() {
      if (!this.progressTotal) return 0;
      return Math.min(100, Math.round((this.progressCurrent / this.progressTotal) * 100));
    },
    isIndeterminate() {
      if (this.progressPhase === 'zip') return true;
      // Until the first bytes are reported
      if (this.progressPhase === 'upload' && this.progressCurrent === 0) return true;
      return false;
    },
    progressLabel() {
      const phase = this.progressPhase;
      if (!phase || phase === 'starting') return this.t('progress.preparing');
      if (phase === 'zip') return this.t('progress.zip');
      if (phase === 'upload') {
        return this.progressTotal && this.progressCurrent
          ? this.t('progress.uploadBytes', {
            sent: this.formatSize(this.progressCurrent),
            total: this.formatSize(this.progressTotal),
            percent: this.progressPercent
          })
          : this.t('progress.upload');
      }
      if (phase === 'cleanup') return this.t('progress.cleanup');
      if (phase === 'cancelling') return this.t('progress.cancelling');
      if (phase === 'done') return this.t('progress.done');
      if (phase === 'cancelled') return this.t('progress.cancelled');
      if (phase === 'error') return this.t('progress.error', {message: this.progressMessage});
      return this.progressMessage || '';
    },
    ftpStatsConnection() {
      return this.formatConnection(this.ftpStats && this.ftpStats.connection);
    }
  },

  created() {
    this.checkFtpSettings().then(() => this.loadBackups());
  },

  beforeDestroy() {
    this.stopProgressPolling();
  },

  methods: {
    t(key, data) {
      return this.$t('tearoom1.ftp-backup.' + key, data);
    },

    api(path) {
      return window.panel.urls.api + '/ftp-backup/' + path;
    },

    formatDate(timestamp) {
      return timestamp
        ? new Date(timestamp * 1000).toLocaleString(this.locale, {dateStyle: 'medium', timeStyle: 'short'})
        : '';
    },

    formatConnection(connection) {
      if (!connection) {
        return '';
      }

      const protocol = connection.protocol ? `${connection.protocol.toLowerCase()}://` : '';
      const host = connection.host || '(no host configured)';
      const port = connection.port ? `:${connection.port}` : '';
      const path = connection.path ? connection.path.replace(/^(?!\/)/, '/') : '/';

      return `${protocol}${host}${port}${path}`;
    },

    formatDay(timestamp) {
      return new Date(timestamp * 1000).toLocaleDateString(this.locale, {day: '2-digit', month: '2-digit', year: 'numeric'});
    },

    formatTime(timestamp) {
      return new Date(timestamp * 1000).toLocaleTimeString(this.locale, {hour: '2-digit', minute: '2-digit'});
    },

    // Shown instead of the two check columns on narrow screens
    locationLabel(backup) {
      if (!this.ftpConfigured || this.remoteFiles === null) {
        return '';
      }
      if (backup.local && backup.remote) return this.t('list.both');
      return backup.local ? this.t('list.local') : this.t('list.remote');
    },

    itemOptions(backup) {
      const options = [];
      if (backup.local) {
        options.push({icon: 'download', text: this.t('option.download'), click: () => window.open(backup.downloadUrl, '_blank')});
      }
      options.push(backup.protected
        ? {icon: 'unlock', text: this.t('option.unprotect'), click: () => this.protectBackup(backup, false)}
        : {icon: 'lock', text: this.t('option.protect'), click: () => this.protectBackup(backup, true)});

      if (!backup.protected) {
        options.push('-');
        if (backup.local) {
          options.push({icon: 'trash', text: this.t('option.deleteLocal'), click: () => this.confirmDelete(backup, false)});
        }
        if (backup.remote) {
          options.push({
            icon: 'trash',
            text: backup.local ? this.t('option.deleteBoth') : this.t('option.deleteRemote'),
            click: () => this.confirmDelete(backup, true)
          });
        }
      }
      return options;
    },

    // Kirby's API turns a result with status "error" into a failed request
    async postAction(path, data) {
      try {
        return await this.$api.post(path, data);
      } catch (error) {
        return {status: 'error', message: error.message};
      }
    },

    async protectBackup(backup, isProtected) {
      const response = await this.postAction('ftp-backup/protect', {filename: backup.filename, protected: isProtected});
      if (response.status === 'success') {
        window.panel.notification.success(this.t(isProtected ? 'notify.protected' : 'notify.unprotected'));
      } else {
        window.panel.notification.error(response.message);
      }
      this.loadBackups();
    },

    confirmDelete(backup, remote) {
      const key = remote ? (backup.local ? 'delete.confirmBoth' : 'delete.confirmRemote') : 'delete.confirmLocal';
      window.panel.dialog.open({
        component: 'k-remove-dialog',
        props: {
          text: this.t(key, {filename: backup.filename}) + ' ' + this.t('delete.hint')
        },
        on: {
          submit: async () => {
            const response = await this.postAction('ftp-backup/delete', {filename: backup.filename, remote});
            window.panel.dialog.close();
            if (response.status === 'success') {
              window.panel.notification.success(this.t('notify.deleted'));
            } else {
              window.panel.notification.error(response.message);
            }
            this.loadBackups();
          }
        }
      });
    },

    async loadBackups() {
      this.isLoadingBackups = true;

      try {
        const response = await this.$api.get('ftp-backup/backups');

        if (response.status === 'success') {
          this.localBackups = response.data || [];
          this.localStats = response.stats || this.localStats;
          this.health = response.health || null;
        } else {
          this.localBackups = [];
        }
      } catch (error) {
        window.panel.notification.error(this.t('notify.loadFailed'));
        this.localBackups = [];
      } finally {
        this.isLoadingBackups = false;
      }

      this.loadRemoteFiles();
    },

    // The server listing needs a connection, so it is loaded after the local list
    async loadRemoteFiles() {
      if (!this.ftpConfigured) {
        this.remoteFiles = null;
        return;
      }

      this.isLoadingRemote = true;
      this.remoteError = null;
      try {
        const response = await this.$api.get('ftp-backup/ftp-stats');
        if (response.status === 'success' && response.data) {
          this.remoteFiles = response.data.files || [];
          this.ftpStats = response.data;
        } else {
          this.remoteFiles = null;
          this.remoteError = response.message || '';
        }
      } catch (error) {
        this.remoteFiles = null;
        this.remoteError = error.message || '';
      } finally {
        this.isLoadingRemote = false;
      }
    },

    async testConnection() {
      this.connectionTest = null;
      this.isTestingConnection = true;
      this.$refs.testDialog.open();
      try {
        this.connectionTest = (await this.$api.post('ftp-backup/test-connection')).data;
      } catch (error) {
        this.connectionTest = {status: 'error', steps: [{step: 'connect', ok: false, message: error.message}]};
      } finally {
        this.isTestingConnection = false;
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

            if (d.phase === 'cancelled') {
              this.finishCancelledBackup();
            }
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
        await this.$api.post('ftp-backup/cancel', {jobId: this.currentJobId});
        this.progressPhase = 'cancelling';
      } catch (e) {
        window.panel.notification.error(this.t('notify.cancelFailed'));
        this.isCancelling = false;
      }
    },

    finishCancelledBackup() {
      if (!this.currentJobId && !this.isCreatingBackup && !this.isCancelling) {
        return;
      }

      this.stopProgressPolling();
      this.isCreatingBackup = false;
      this.isCancelling = false;
      this.currentJobId = null;
      window.panel.notification.info(this.t('notify.cancelled'));
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
        // Plain fetch so a non-JSON reply (e.g. a proxy timeout page) can be explained;
        // the API URL respects installations in a subfolder and custom API slugs
        const rawResponse = await fetch(this.api('create'), {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF': window.panel.system.csrf,
          },
          body: JSON.stringify({jobId: this.currentJobId}),
        });

        const contentType = rawResponse.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
          throw new Error(rawResponse.status === 504
            ? this.t('error.timeout')
            : this.t('error.http', {status: rawResponse.status}));
        }

        const response = await rawResponse.json();

        if (response.status === 'cancelled') {
          this.finishCancelledBackup();
          return;
        }

        if (response.status === 'success') {
          window.panel.notification.success(response.message);

          // Check if FTP upload failed (but only if FTP is enabled)
          if (response.data && response.data.ftpResult && !response.data.ftpResult.uploaded && !response.data.ftpResult.disabled) {
            this.showFtpWarning(response.data.ftpResult.message || 'FTP upload failed', true);
          } else if (this.ftpConfigured) {
            this.dismissWarning();
          }
        } else {
          // Show the actual error message from the backend
          const errorMessage = response.message || 'Failed to create backup';
          window.panel.notification.error(errorMessage);

          // Show warning if the error appears to be FTP-related
          if (errorMessage.toLowerCase().includes('ftp')) {
            this.showFtpWarning(errorMessage, true);
          }
        }
      } catch (error) {
        const errorMessage = error.message || 'Failed to create backup';
        window.panel.notification.error(errorMessage);
        console.error('Backup creation error:', error);
        this.showFtpWarning(errorMessage, true);
      } finally {
        this.stopProgressPolling();
        this.isCreatingBackup = false;
        this.isCancelling = false;
        this.currentJobId = null;
        this.loadBackups();
      }
    },

    showSettingsInfo() {
      this.$refs.settingsDialog.open();
    },

    // FTP Server Stats methods
    showFtpServerStats() {
      this.$refs.ftpStatsDialog.open();
      this.loadFtpServerStats();
    },

    async loadFtpServerStats() {
      this.isLoadingFtpStats = true;
      this.ftpStatsError = null;
      this.ftpStatsErrorConnection = '';

      try {
        const response = await this.$api.get('ftp-backup/ftp-stats');

        if (response.status === 'success' && response.data) {
          this.ftpStats = response.data;
          this.remoteFiles = response.data.files || [];
        } else {
          this.ftpStatsError = response.message || 'Failed to load FTP server stats';
          this.ftpStatsErrorConnection = this.formatConnection(response.connection);
          this.ftpStats = null;
        }
      } catch (error) {
        this.ftpStatsError = error.message || 'Failed to load FTP server stats';
        this.ftpStats = null;
      } finally {
        this.isLoadingFtpStats = false;
      }
    },

    // FTP Warning Panel Methods
    // kind 'config' warnings disappear once the settings are complete
    showFtpWarning(message, isPersistent = false, kind = 'upload') {
      this.ftpWarning = {
        show: true,
        message: message,
        isPersistent: isPersistent,
        kind: kind
      };

      // Keep the warning for this browser session
      if (isPersistent) {
        try {
          sessionStorage.setItem('ftpBackupWarning', JSON.stringify(this.ftpWarning));
        } catch (e) {
          // storage unavailable (private mode)
        }
      }
    },

    dismissWarning() {
      this.ftpWarning.show = false;
      try {
        sessionStorage.removeItem('ftpBackupWarning');
      } catch (e) {
        // storage unavailable (private mode)
      }
    },

    async checkFtpSettings() {
      try {
        const savedWarning = sessionStorage.getItem('ftpBackupWarning');
        if (savedWarning) {
          this.ftpWarning = JSON.parse(savedWarning);
        }
      } catch (e) {
        // storage unavailable or invalid JSON
      }

      try {
        const response = await this.$api.get('ftp-backup/settings-status');
        if (response.status !== 'success') {
          return;
        }

        this.ftpConfigured = response.data.configured;
        if (response.data.ftpEnabled === false) {
          this.showFtpWarning(this.t('warning.disabled'), true, 'config');
        } else if (!response.data.configured) {
          this.showFtpWarning(this.t('warning.notConfigured', {reason: response.data.reason || ''}), true, 'config');
        } else if (this.ftpWarning.kind === 'config') {
          this.dismissWarning();
        }
      } catch (e) {
        // Silent failure, we don't want to show errors for this check
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

      const number = new Intl.NumberFormat(this.locale, {maximumFractionDigits: unitIndex === 0 ? 0 : 1}).format(size);
      return `${number} ${units[unitIndex]}`;
    }
  }
};
</script>

<style>
/* Status colours that keep their contrast in the light and dark Panel theme
   (Kirby switches the theme through color-scheme) */
.k-ftp-backup-view {
  --ftp-backup-yes: light-dark(var(--color-green-700), var(--color-green-400));
  --ftp-backup-delete: light-dark(var(--color-red-700), var(--color-red-400));
  --ftp-backup-protected: light-dark(var(--color-blue-700), var(--color-blue-400));
}

.k-ftp-backup-view-sponsor-button {
  --button-color-icon: #e0245e;
}

.k-ftp-backup-view-sponsor-button:hover {
  --button-color-icon: #b81d4d;
}

.k-ftp-backup-view-status {
  display: grid;
  gap: var(--spacing-2);
}

/* The latest backup needs the most room: date, filename and location */
.k-ftp-backup-view-overview {
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1.5fr);
}

.k-ftp-backup-view-overview .k-stat-value {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.k-ftp-backup-view-overview-file {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

@media (max-width: 40rem) {
  .k-ftp-backup-view-overview {
    grid-template-columns: minmax(0, 1fr);
  }
}

.k-ftp-backup-table {
  container-type: inline-size;
}

.k-ftp-backup-table table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  background: var(--item-color-back, var(--color-white));
  border-radius: var(--rounded);
  box-shadow: var(--shadow);
  font-size: var(--text-sm);
}

.k-ftp-backup-table th {
  padding: 0.6rem 0.75rem;
  text-align: start;
  font-weight: var(--font-normal);
  color: var(--color-text-dimmed);
  border-bottom: 1px solid var(--color-border);
  white-space: nowrap;
}

.k-ftp-backup-table td {
  height: 2.5rem;
  padding: 0 0.75rem;
  border-bottom: 1px solid var(--color-border);
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.k-ftp-backup-table tr:last-child td {
  border-bottom: 0;
}

.k-ftp-backup-table .is-right {
  text-align: end;
}

.k-ftp-backup-table .is-center {
  text-align: center;
}

.k-ftp-backup-table .is-yes {
  display: inline-flex;
  color: var(--ftp-backup-yes);
}

.k-ftp-backup-table .is-no {
  color: var(--color-text-dimmed);
}

.k-ftp-backup-table-date strong {
  font-weight: var(--font-bold);
}

.k-ftp-backup-table-date span {
  margin-inline-start: 0.4rem;
  color: var(--color-text-dimmed);
}

.k-ftp-backup-table-file {
  width: 100%;
  max-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  color: var(--color-text-dimmed);
}

.k-ftp-backup-table-location {
  display: none;
}

.k-ftp-backup-table-notes .k-ftp-backup-tag + .k-ftp-backup-tag {
  margin-inline-start: 0.25rem;
}

.k-ftp-backup-table-options {
  width: 1%;
  padding-inline: 0.25rem !important;
}

.k-ftp-backup-tag {
  display: inline-block;
  padding: 0 0.45rem;
  border-radius: 999px;
  font-size: var(--text-xs);
  line-height: 1.5;
  box-shadow: inset 0 0 0 1px currentColor;
}

.k-ftp-backup-tag.is-protected {
  color: var(--ftp-backup-protected);
}

.k-ftp-backup-tag.is-delete {
  color: var(--ftp-backup-delete);
}

.k-ftp-backup-sr {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
}

/* Medium: the filename column would only show a few characters */
@container (max-width: 52rem) {
  .k-ftp-backup-table th:nth-last-child(2),
  .k-ftp-backup-table-file {
    display: none;
  }
}

/* Narrow: one stacked row per backup with date, size and location */
@container (max-width: 34rem) {
  .k-ftp-backup-table table,
  .k-ftp-backup-table tbody,
  .k-ftp-backup-table tr,
  .k-ftp-backup-table td {
    display: block;
  }

  .k-ftp-backup-table thead,
  .k-ftp-backup-table-flag,
  .k-ftp-backup-table-file {
    display: none !important;
  }

  .k-ftp-backup-table tr {
    position: relative;
    padding: 0.55rem 2.5rem 0.55rem 0.75rem;
    border-bottom: 1px solid var(--color-border);
  }

  .k-ftp-backup-table tr:last-child {
    border-bottom: 0;
  }

  .k-ftp-backup-table td {
    height: auto;
    padding: 0;
    border: 0;
  }

  .k-ftp-backup-table-size,
  .k-ftp-backup-table-location {
    display: inline !important;
    color: var(--color-text-dimmed);
  }

  .k-ftp-backup-table-location:not(:empty)::before {
    content: " · ";
  }

  .k-ftp-backup-table-notes:not(:empty) {
    margin-top: 0.3rem;
  }

  .k-ftp-backup-table-options {
    position: absolute;
    top: 0.3rem;
    right: 0.25rem;
  }
}

.k-ftp-backup-view-list-note {
  margin-top: 0.75rem;
  font-size: var(--text-sm);
  color: var(--color-text-dimmed);
}

.k-ftp-backup-test-steps {
  display: grid;
  gap: 0.75rem;
  margin: 1rem 0;
}

.k-ftp-backup-test-steps li {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
}

.k-ftp-backup-test-steps li.is-ok .k-icon {
  color: var(--ftp-backup-yes);
}

.k-ftp-backup-test-steps li.is-failed .k-icon {
  color: var(--ftp-backup-delete);
}

.k-ftp-backup-test-steps p {
  margin-top: 0.15rem;
  font-size: var(--text-sm);
  color: var(--color-text-dimmed);
  overflow-wrap: anywhere;
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
  0% {
    transform: translateX(-100%);
  }
  100% {
    transform: translateX(250%);
  }
}

.k-ftp-backup-progress-label {
  margin-top: 0.5rem;
  font-size: var(--text-sm);
  color: var(--color-text-light);
}

.k-ftp-backup-files-title {
  font-size: 1rem;
  line-height: 1.25;
}

.k-ftp-backup-dialog-loading {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin: 1rem 0;
  color: var(--color-text-dimmed);
}

.k-ftp-backup-connection {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  margin: 0.75rem 0 1rem;
  padding: 0.55rem 0.75rem;
  border-radius: 4px;
  border: 1px solid var(--color-border);
  background: var(--theme-color-back);
}

.k-ftp-backup-connection span {
  flex-shrink: 0;
  color: var(--color-text-light);
  font-size: var(--text-sm);
  font-weight: var(--font-bold);
}

.k-ftp-backup-connection code {
  overflow-wrap: anywhere;
  font-family: var(--font-mono);
  font-size: var(--text-sm);
  line-height: 1.25;
}
</style>
