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
    
    <!-- Tabs Navigation -->
    <nav class="k-tabs">
      <k-button
        v-for="tab in tabs"
        :key="tab.name"
        :current="activeTab === tab.name"
        @click="activeTab = tab.name"
        class="k-tab-button"
      >
        {{ tab.label }}
      </k-button>
    </nav>

    <!-- Backups Tab -->
    <div v-show="activeTab === 'backups'" class="k-tab-content">
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
    
    <!-- Settings Tab -->
    <div v-show="activeTab === 'settings'" class="k-tab-content">
      <k-form
        :fields="formFields"
        v-model="ftpSettings"
        @submit="saveSettings"
      />
    </div>
    
    <!-- Cron Tab -->
    <div v-show="activeTab === 'cron'" class="k-tab-content">
      <div class="k-ftp-backup-view-section">
        <h2>Cron Job Setup</h2>
        <p>Use the following command in your crontab to schedule automatic backups:</p>
        
        <div class="k-ftp-backup-view-cron">
          {{ cronCommand }}
          <button @click="copyCronCommand">
            <k-icon type="copy" />
          </button>
        </div>
        
        <p>Example crontab entry to run daily at 2 AM:</p>
        <div class="k-ftp-backup-view-cron">
          0 2 * * * {{ cronCommand }}
          <button @click="copyCrontabEntry">
            <k-icon type="copy" />
          </button>
        </div>
      </div>
    </div>
  </k-panel-inside>
</template>

<script>
export default {
  props: {
    stats: Object,
    cronCommand: String
  },
  
  data() {
    return {
      activeTab: 'settings',
      isLoading: false,
      isCreatingBackup: false,
      isLoadingBackups: false,
      ftpSettings: {
        host: '',
        port: 21,
        username: '',
        password: '',
        directory: '/',
        passive: true,
        ssl: false
      },
      backups: [],
      tabs: [
        { name: 'backups', label: 'Backups' },
        { name: 'settings', label: 'Settings' },
        { name: 'cron', label: 'Cron Job' }
      ]
    };
  },
  
  computed: {
    formFields() {
      return {
        host: {
          label: 'FTP Host',
          type: 'text',
          required: true
        },
        port: {
          label: 'FTP Port',
          type: 'number',
          default: 21
        },
        username: {
          label: 'Username',
          type: 'text',
          required: true
        },
        password: {
          label: 'Password',
          type: 'password',
          help: 'Leave empty to keep existing password'
        },
        directory: {
          label: 'Remote Directory',
          type: 'text',
          default: '/'
        },
        passive: {
          label: 'Passive Mode',
          type: 'toggle',
          default: true,
          text: ['Off', 'On']
        },
        ssl: {
          label: 'Use SSL/TLS',
          type: 'toggle',
          default: false,
          text: ['Off', 'On']
        }
      };
    }
  },
  
  created() {
    this.loadSettings();
    this.loadBackups();
  },
  
  methods: {
    async loadSettings() {
      this.isLoading = true;
      
      try {
        const response = await this.$api.get('ftp-backup/settings');
        this.ftpSettings = response;
      } catch (error) {
        this.$store.dispatch('notification/error', 'Failed to load FTP settings');
      } finally {
        this.isLoading = false;
      }
    },
    
    async saveSettings() {
      this.isLoading = true;
      
      try {
        const response = await this.$api.post('ftp-backup/settings', this.ftpSettings);
        
        if (response.status === 'success') {
          this.$store.dispatch('notification/success', response.message);
        } else {
          this.$store.dispatch('notification/error', response.message);
        }
      } catch (error) {
        this.$store.dispatch('notification/error', 'Failed to save FTP settings');
      } finally {
        this.isLoading = false;
      }
    },
    
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
        this.$store.dispatch('notification/error', 'Failed to load backups');
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
        window.open(item.link, '_blank');
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
    },
    
    copyCronCommand() {
      navigator.clipboard.writeText(this.cronCommand);
      this.$store.dispatch('notification/success', 'Command copied to clipboard');
    },
    
    copyCrontabEntry() {
      navigator.clipboard.writeText(`0 2 * * * ${this.cronCommand}`);
      this.$store.dispatch('notification/success', 'Crontab entry copied to clipboard');
    }
  }
};
</script>
<style>

</style>
