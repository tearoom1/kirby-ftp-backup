<template>
  <k-panel-inside class="k-ftp-backup-view">
    <k-header>
      FTP Backup
      <template #buttons>
        <k-button
          class="k-ftp-backup-view-sponsor-button"
          icon="heart"
          size="sm"
          :responsive="true"
          link="https://www.tearoom.one/en/kirby-plugins/support"
          target="_blank"
          :text="t('sponsor.label')"
        />
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
        <k-options-dropdown
          icon="dots"
          size="sm"
          variant="filled"
          :options="headerOptions"
        />
      </template>
    </k-header>

    <!-- Three tiles in Kirby's stat style: label, value, one info line. They also carry
         the state (running backup, failed upload, unreachable server), so the page never
         gets extra rows above the list and nothing below them moves -->
    <k-section class="k-ftp-backup-tiles">
      <dl class="k-stats" data-size="large">
        <div class="k-stat k-ftp-backup-tile" :data-state="lastBackupState">
          <dt class="k-stat-label">{{ t('tile.last') }}</dt>
          <dd class="k-stat-value">{{ lastBackupValue }}</dd>
          <dd class="k-stat-info">
            <!-- a problem opens its full text in a dialog -->
            <button
              v-if="lastBackupProblem && !isCreatingBackup"
              type="button"
              class="k-ftp-backup-tile-link"
              @click="openProblem('last')"
            >{{ lastBackupInfo }}</button>
            <template v-else>{{ lastBackupInfo }}</template>
          </dd>
          <!-- progress as a thin bar on the tile's lower edge: takes no room -->
          <div v-if="isCreatingBackup" class="k-ftp-backup-tile-bar">
            <div v-if="isIndeterminate" class="k-ftp-backup-progress-indeterminate"></div>
            <div v-else class="k-ftp-backup-progress-fill" :style="{ width: progressPercent + '%' }"></div>
          </div>
        </div>

        <div class="k-stat k-ftp-backup-tile">
          <dt class="k-stat-label">{{ t('tile.local') }}</dt>
          <dd class="k-stat-value">{{ backupCount(localBackups.length) }}</dd>
          <dd class="k-stat-info">{{ formatSize(localTotalSize) }}</dd>
        </div>

        <div class="k-stat k-ftp-backup-tile" :data-state="remoteProblem ? 'error' : (remoteStale ? 'old' : null)">
          <!-- the server address opens above the label, so it never covers the line below -->
          <dt v-ftp-backup-tooltip.top="remoteTarget" class="k-stat-label">{{ t('tile.remote') }}</dt>
          <dd class="k-stat-value">{{ remoteValue }}</dd>
          <dd class="k-stat-info">
            <button
              v-if="remoteProblem"
              type="button"
              class="k-ftp-backup-tile-link"
              @click="openProblem('remote')"
            >{{ remoteInfo }}</button>
            <template v-else>{{ remoteInfo }}</template>
          </dd>
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
            <!-- date and time in two columns, so all times start at the same position -->
            <th colspan="2">{{ t('list.created') }}</th>
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
            <td class="k-ftp-backup-table-date">{{ formatDay(backup.modified) }}</td>
            <td class="k-ftp-backup-table-time">{{ formatTime(backup.modified) }}</td>
            <td class="k-ftp-backup-table-size is-right">{{ formatSize(backup.size || 0) }}</td>
            <td class="k-ftp-backup-table-flag is-center">
              <k-icon v-if="backup.local" v-ftp-backup-tooltip="t('list.colLocal')" :alt="t('list.colLocal')" type="check" class="is-yes"/>
              <span v-else class="is-no">–</span>
            </td>
            <td v-if="ftpConfigured" class="k-ftp-backup-table-flag is-center">
              <span v-if="remoteFiles === null" v-ftp-backup-tooltip="isLoadingRemote ? t('list.loadingServer') : ''" class="is-no">?</span>
              <k-icon v-else-if="backup.remote" v-ftp-backup-tooltip="t('list.colServer')" :alt="t('list.colServer')" type="check" class="is-yes"/>
              <span v-else class="is-no">–</span>
            </td>
            <td class="k-ftp-backup-table-location">{{ locationLabel(backup) }}</td>
            <td class="k-ftp-backup-table-notes">
              <span v-if="backup.protected" class="k-ftp-backup-tag is-protected">{{ t('list.protected') }}</span>
              <span v-if="deleteNoteKey(backup)" class="k-ftp-backup-tag is-delete">{{ t(deleteNoteKey(backup)) }}</span>
            </td>
            <td v-ftp-backup-tooltip="backup.filename" class="k-ftp-backup-table-file">{{ backup.filename }}</td>
            <td class="k-ftp-backup-table-options">
              <k-button
                v-if="busyFile === backup.filename"
                icon="download"
                size="sm"
                :progress="true"
                :disabled="true"
                v-ftp-backup-tooltip="t('list.fetching')"
                :aria-label="t('list.fetching')"
              />
              <k-options-dropdown v-else :options="itemOptions(backup)"/>
            </td>
          </tr>
          </tbody>
        </table>
      </div>
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

  </k-panel-inside>
</template>

<script>
import {deleteNote, isSameDialog, isTooOld, mergeBackups, newestBackup} from '../backups.js';

export default {
  props: {
    stats: Object,
    // Cached server listing from the area, so the view is complete on first render
    server: Object,
    isAdmin: Boolean
  },

  data() {
    return {
      isLoading: false,
      isCreatingBackup: false,
      isCancelling: false,
      // true until the first list arrives, so the empty state does not flash
      isLoadingBackups: true,
      isLoadingRemote: false,
      isTestingConnection: false,
      // Backup that is being fetched from the server (download or copy)
      busyFile: null,
      localStats: this.stats || {},
      localBackups: [],
      // null means unknown ("?"): no listing yet, or a cached failed check without one
      remoteFiles: this.server && Array.isArray(this.server.files) ? this.server.files : null,
      // A cached failed check carries the error, so the box is there on first render
      remoteError: this.server && typeof this.server.error === 'string' ? this.server.error : null,
      health: null,
      ftpSettings: null,
      ftpConfigured: false,
      connectionTest: null,
      ftpWarning: {
        show: false,
        message: '',
        isPersistent: false
      },
      ftpStats: this.server || null,
      // the next load can skip the request when the area already sent the listing
      remoteFromProps: Boolean(this.server),
      // ticks every minute for "checked 5 min ago"
      now: Date.now(),
      nowTimer: null,
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
    // Full text of a problem with the last backup (failed upload or failed run), for the tooltip
    lastBackupProblem() {
      if (!this.ftpWarning.show || this.ftpWarning.kind === 'config') {
        return null;
      }
      return this.ftpWarning.kind === 'upload'
        ? this.t('warning.uploadFailed', {message: String(this.ftpWarning.message).replace(/^FTP upload failed:\s*/i, '')})
        : this.ftpWarning.message;
    },

    // Full text of a problem with the FTP server (settings or connection), for the tooltip
    remoteProblem() {
      if (this.ftpWarning.show && this.ftpWarning.kind === 'config') {
        return this.ftpWarning.message;
      }
      return this.remoteError ? this.t('list.serverError', {message: this.remoteError}) : null;
    },

    lastBackupValue() {
      if (this.isCreatingBackup) {
        return this.isIndeterminate || !this.progressTotal
          ? this.t('tile.running')
          : this.t('tile.running') + ' · ' + this.progressPercent + ' %';
      }
      return this.latestBackup ? this.formatAge(this.latestBackup.modified) : this.t('stats.none');
    },


    locale() {
      const translation = window.panel && window.panel.translation;
      return (translation && translation.code) || undefined;
    },

    // Local backups and server copies merged by filename, newest first
    listItems() {
      return mergeBackups(this.localBackups, this.remoteFiles);
    },

    // Header "…" menu; the setup help is only for admins
    headerOptions() {
      const options = [
        {icon: 'check', text: this.t('action.test'), click: () => this.testConnection(), disabled: this.isCreatingBackup || this.isTestingConnection}
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
        // Loading shows on the button instead of a line that comes and goes below the list
        progress: this.isLoadingBackups || this.isLoadingRemote,
        // Always reads the server, the cached listing is skipped
        click: () => this.loadBackups(true)
      }];
    },

    // The server values stay "–" until the server listing has arrived
    remoteKnown() {
      return this.ftpConfigured && this.remoteFiles !== null;
    },

    localTotalSize() {
      return this.localBackups.reduce((sum, backup) => sum + (backup.size || 0), 0);
    },

    remoteValue() {
      if (this.ftpSettings && this.ftpSettings.ftpEnabled === false) {
        return this.t('tile.ftpOff');
      }
      if (!this.ftpConfigured) {
        return this.ftpSettings ? this.t('tile.ftpNotSetUp') : '–';
      }
      if (this.remoteKnown) {
        return this.backupCount(this.remoteFiles.length);
      }
      return this.remoteProblem ? this.t('tile.unreachable') : '–';
    },

    remoteInfo() {
      if (this.ftpSettings && this.ftpSettings.ftpEnabled === false) {
        return this.t('tile.ftpOffInfo');
      }
      if (!this.ftpConfigured) {
        return this.ftpSettings ? this.t('tile.ftpLocalOnly') : '–';
      }
      if (this.remoteStale) {
        return this.t('tile.unreachable') + ' · ' + this.t('tile.lastChecked', {time: this.formatChecked(this.ftpStats.checkedAt)});
      }
      if (this.remoteKnown && this.ftpStats) {
        const size = this.formatSize(this.remoteFiles.reduce((sum, file) => sum + (file.size || 0), 0));
        return size + ' · ' + this.formatCheckedLine(this.ftpStats.checkedAt);
      }
      return this.remoteProblem ? this.t('tile.unreachableWhy') : '–';
    },

    // The newest backup in the merged list, so a copy only on the server counts too
    latestBackup() {
      return newestBackup(this.listItems);
    },

    lastBackupState() {
      if (this.isCreatingBackup) {
        return 'running';
      }
      if (this.lastBackupProblem) {
        return 'error';
      }
      if (this.isStale) {
        return 'old';
      }
      return this.latestBackup ? 'ok' : null;
    },

    lastBackupInfo() {
      if (this.isCreatingBackup) {
        return this.progressLabel;
      }
      if (this.lastBackupProblem) {
        return this.ftpWarning.kind === 'upload' ? this.t('tile.uploadFailed') : this.ftpWarning.message;
      }
      if (this.isStale) {
        return this.latestBackup ? this.t('stats.stale', {hours: this.maxBackupAge}) : this.t('stats.staleNone');
      }
      return this.latestBackup ? this.formatDate(this.latestBackup.modified) : '–';
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
      return isTooOld(this.latestBackup, this.maxBackupAge, Date.now() / 1000);
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
    remoteTarget() {
      return this.ftpStats ? this.formatConnection(this.ftpStats.connection) : '';
    },

    // Showing the last listing because the server could not be reached
    remoteStale() {
      return Boolean(this.ftpStats && this.ftpStats.stale);
    }
  },

  created() {
    this.checkFtpSettings().then(() => this.loadBackups());
    this.nowTimer = setInterval(() => {
      this.now = Date.now();
    }, 60000);
  },

  beforeDestroy() {
    this.stopProgressPolling();
    clearInterval(this.nowTimer);
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

    // "2 days ago", in the Panel language
    formatAge(timestamp) {
      const seconds = Math.round(timestamp - Date.now() / 1000);
      const abs = Math.abs(seconds);
      const format = new Intl.RelativeTimeFormat(this.locale, {numeric: 'auto'});
      if (abs < 3600) return format.format(Math.round(seconds / 60), 'minute');
      if (abs < 2 * 86400) return format.format(Math.round(seconds / 3600), 'hour');
      if (abs < 60 * 86400) return format.format(Math.round(seconds / 86400), 'day');
      return format.format(Math.round(seconds / (30 * 86400)), 'month');
    },

    // "checked 5 min ago"; this.now keeps it current
    formatChecked(timestamp) {
      const seconds = Math.round(timestamp - this.now / 1000);
      const format = new Intl.RelativeTimeFormat(this.locale, {numeric: 'auto', style: 'short'});
      if (Math.abs(seconds) < 3600) return format.format(Math.round(seconds / 60), 'minute');
      if (Math.abs(seconds) < 86400) return format.format(Math.round(seconds / 3600), 'hour');
      return format.format(Math.round(seconds / 86400), 'day');
    },

    formatCheckedLine(timestamp) {
      return this.now / 1000 - timestamp < 60
        ? this.t('tile.checkedNow')
        : this.t('tile.checked', {time: this.formatChecked(timestamp)});
    },

    backupCount(count) {
      return count === 1 ? this.t('tile.countOne') : this.t('tile.count', {count});
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

    // Translation key of the retention note: list.deleteNext, list.deleteNextLocal or list.deleteNextRemote
    deleteNoteKey(backup) {
      const note = deleteNote(backup, this.remoteFiles !== null);
      if (!note) return null;
      return {both: 'list.deleteNext', local: 'list.deleteNextLocal', remote: 'list.deleteNextRemote'}[note];
    },

    formatDay(timestamp) {
      return new Date(timestamp * 1000).toLocaleDateString(this.locale, {day: 'numeric', month: 'short', year: 'numeric'});
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
      const busy = this.busyFile !== null;
      if (backup.local) {
        options.push({icon: 'download', text: this.t('option.download'), click: () => window.open(backup.downloadUrl, '_blank')});
      } else if (backup.remote) {
        // Only on the server: fetched through this web server, which takes a while
        options.push({icon: 'download', text: this.t('option.download'), disabled: busy, click: () => this.downloadFromServer(backup)});
        options.push({icon: 'copy', text: this.t('option.copyLocal'), disabled: busy, click: () => this.copyFromServer(backup)});
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

    async downloadFromServer(backup) {
      this.busyFile = backup.filename;
      const response = await this.postAction('ftp-backup/server-download', {filename: backup.filename});
      this.busyFile = null;
      if (response.status === 'success' && response.data) {
        // The file is sent as an attachment, the Panel stays open
        window.location.assign(response.data.url);
      } else {
        window.panel.notification.error(response.message);
      }
    },

    async copyFromServer(backup) {
      this.busyFile = backup.filename;
      const response = await this.postAction('ftp-backup/copy-from-server', {filename: backup.filename});
      this.busyFile = null;
      if (response.status === 'success') {
        window.panel.notification.success(this.t('notify.copied'));
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

    async loadBackups(fresh = false) {
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

      this.loadRemoteFiles(fresh);
    },

    // The server listing (cached on the server) is loaded after the local list
    async loadRemoteFiles(fresh = false) {
      if (!this.ftpConfigured) {
        this.remoteFiles = null;
        return;
      }
      if (this.remoteFromProps && !fresh) {
        this.remoteFromProps = false;
        return;
      }
      this.remoteFromProps = false;

      // The error stays visible while the server is read again, so nothing jumps;
      // it changes only when the answer is there
      this.isLoadingRemote = true;
      try {
        const response = await this.$api.get('ftp-backup/ftp-stats', fresh ? {fresh: 1} : undefined);
        if (response.status === 'success' && response.data) {
          this.remoteFiles = response.data.files || [];
          this.ftpStats = response.data;
          this.remoteError = response.data.stale ? (response.data.error || '') : null;
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
          const upload = response.data && response.data.ftpResult;

          // Upload failed (FTP enabled): the box says the backup is local and why,
          // no green "created" message next to it
          if (upload && !upload.uploaded && !upload.disabled) {
            this.showFtpWarning(upload.message || 'FTP upload failed', true);
          } else {
            window.panel.notification.success(response.message);
            if (this.ftpConfigured) {
              this.dismissWarning();
            }
          }
        } else {
          // Show the actual error message from the backend
          const errorMessage = response.message || 'Failed to create backup';
          window.panel.notification.error(errorMessage);

          // Show warning if the error appears to be FTP-related
          if (errorMessage.toLowerCase().includes('ftp')) {
            this.showFtpWarning(errorMessage, true, 'error');
          }
        }
      } catch (error) {
        const errorMessage = error.message || 'Failed to create backup';
        window.panel.notification.error(errorMessage);
        console.error('Backup creation error:', error);
        this.showFtpWarning(errorMessage, true, 'error');
      } finally {
        this.stopProgressPolling();
        this.isCreatingBackup = false;
        this.isCancelling = false;
        this.currentJobId = null;
        this.loadBackups();
      }
    },

    // Props of the problem dialog for a red tile
    problemProps(tile) {
      const config = this.ftpWarning.show && this.ftpWarning.kind === 'config';

      if (tile === 'remote') {
        return {
          kind: config ? 'config' : 'remote',
          title: this.t(config ? 'problem.configTitle' : 'problem.remoteTitle'),
          lead: this.t(config ? 'problem.configLead' : 'problem.remoteLead'),
          message: config ? this.ftpWarning.message : this.remoteError,
          endpoint: this.remoteTarget,
          checked: this.ftpStats && this.ftpStats.checkedAt
            ? this.t('tile.lastChecked', {time: this.formatChecked(this.ftpStats.checkedAt)})
            : '',
          loading: false,
          notice: ''
        };
      }

      const upload = this.ftpWarning.kind === 'upload';
      return {
        kind: upload ? 'upload' : 'error',
        title: this.t(upload ? 'problem.uploadTitle' : 'problem.errorTitle'),
        lead: this.t(upload ? 'problem.uploadLead' : 'problem.errorLead'),
        message: String(this.ftpWarning.message).replace(/^FTP upload failed:\s*/i, ''),
        endpoint: this.remoteTarget,
        checked: '',
        loading: false,
        notice: ''
      };
    },

    // Full text of a problem from a red tile, readable in a dialog
    async openProblem(tile) {
      await this.$panel.dialog.open({
        component: 'k-ftp-backup-problem-dialog',
        props: this.problemProps(tile),
        on: {
          cancel: () => this.$panel.dialog.close(),

          // A new attempt stays in the dialog: spinner on the button, then the result
          retry: async () => {
            const dialogId = this.$panel.dialog.id;
            this.$panel.dialog.props = {...this.$panel.dialog.props, loading: true, notice: ''};

            await this.loadRemoteFiles(true);

            if (!isSameDialog(this.$panel.dialog, dialogId)) {
              return;
            }
            if (this.remoteProblem) {
              this.$panel.dialog.props = {...this.problemProps('remote'), notice: this.t('problem.stillFailing')};
            } else {
              await this.$panel.dialog.close();
              window.panel.notification.success(this.t('problem.reachable'));
            }
          },

          dismiss: async () => {
            await this.$panel.dialog.close();
            this.dismissWarning();
          }
        }
      });
    },

    showSettingsInfo() {
      this.$refs.settingsDialog.open();
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

        this.ftpSettings = response.data;
        this.ftpConfigured = response.data.configured;
        // Older versions stored configuration warnings; the FTP tile shows that now
        if (this.ftpWarning.kind === 'config') {
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
  --ftp-backup-old: light-dark(var(--color-orange-700), var(--color-orange-400));
  --ftp-backup-error: light-dark(var(--color-red-700), var(--color-red-400));
}

.k-ftp-backup-view-sponsor-button {
  --button-color-icon: #e0245e;
}

.k-ftp-backup-view-sponsor-button:hover {
  --button-color-icon: #b81d4d;
}

/* Tiles: three columns, stacked when the view is narrow */
.k-ftp-backup-tiles {
  container-type: inline-size;
}

.k-ftp-backup-tiles .k-stats {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

@container (max-width: 36rem) {
  .k-ftp-backup-tiles .k-stats {
    grid-template-columns: minmax(0, 1fr);
  }
}

.k-ftp-backup-tile {
  container-type: inline-size;
  position: relative;
  overflow: hidden;
}

/* Running backup: a thin bar on the lower edge, it takes no room */
.k-ftp-backup-tile-bar {
  position: absolute;
  inset: auto 0 0 0;
  height: 4px;
  background: var(--color-border);
}

.k-ftp-backup-tile[data-state="error"] {
  background: color-mix(in srgb, var(--color-red-500) 16%, var(--stat-color-back));
}

.k-ftp-backup-tile[data-state="error"] :is(.k-stat-value, .k-stat-info) {
  color: var(--ftp-backup-error);
}

/* The problem line is a link that opens the full text in a dialog */
.k-ftp-backup-tile-link {
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  color: inherit;
  text-decoration: underline;
  text-underline-offset: 3px;
  cursor: pointer;
}

.k-ftp-backup-tile-link:focus-visible {
  outline: var(--outline);
  border-radius: var(--rounded-sm);
}

.k-ftp-backup-tile :is(.k-stat-value, .k-stat-info) {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

/* Label with the "Show files" button at the top right */
.k-ftp-backup-tile .k-stat-label {
  justify-content: space-between;
  min-height: var(--height-xs);
}

.k-ftp-backup-tile-action {
  flex-shrink: 0;
}

/* Too narrow for the text: icon only, the tooltip explains it */
@container (max-width: 15rem) {
  .k-ftp-backup-tile-action .k-button-text {
    display: none;
  }
}

.k-ftp-backup-tile[data-state="ok"] .k-stat-value {
  color: var(--ftp-backup-yes);
}

.k-ftp-backup-tile[data-state="old"] {
  background: color-mix(in srgb, var(--color-orange-500) 14%, var(--stat-color-back));
}

.k-ftp-backup-tile[data-state="old"] :is(.k-stat-value, .k-stat-info) {
  color: var(--ftp-backup-old);
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

.k-ftp-backup-table-date {
  font-weight: var(--font-bold);
  padding-inline-end: 0 !important;
}

.k-ftp-backup-table-time {
  padding-inline-start: 0.6rem !important;
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

/* two notes in one cell: "Protected · Deleted on the next run" */
.k-ftp-backup-table-notes .k-ftp-backup-tag + .k-ftp-backup-tag::before {
  content: " · ";
  color: var(--color-text-dimmed);
}

.k-ftp-backup-table-options {
  width: 1%;
  padding-inline: 0.25rem !important;
}

/* A note is coloured text without a frame, so it wraps like any text */
.k-ftp-backup-tag {
  line-height: 1.4;
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
@container (max-width: 58rem) {
  .k-ftp-backup-table th:nth-last-child(2),
  .k-ftp-backup-table-file {
    display: none;
  }

  /* Without the filename the notes take the free space; a long note
     ("deleted on the next run") wraps instead of widening the table.
     Wide tables keep it on one line: there the filename gives way. */
  .k-ftp-backup-table-notes {
    min-width: 12rem;
    white-space: normal !important;
    /* two even lines, never one word alone on the second */
    text-wrap: balance;
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

  .k-ftp-backup-table-date,
  .k-ftp-backup-table-time {
    display: inline !important;
  }

  .k-ftp-backup-table-time {
    padding-inline-start: 0.4rem !important;
  }

  .k-ftp-backup-table-size,
  .k-ftp-backup-table-location {
    display: inline !important;
    color: var(--color-text-dimmed);
  }

  /* size and location on the second line */
  .k-ftp-backup-table-size::before {
    content: "";
    display: block;
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


.k-ftp-backup-progress-fill {
  height: 100%;
  background: var(--color-focus);
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
