(function() {
  "use strict";
  function normalizeComponent(scriptExports, render, staticRenderFns, functionalTemplate, injectStyles, scopeId, moduleIdentifier, shadowMode) {
    var options = typeof scriptExports === "function" ? scriptExports.options : scriptExports;
    if (render) {
      options.render = render;
      options.staticRenderFns = staticRenderFns;
      options._compiled = true;
    }
    return {
      exports: scriptExports,
      options
    };
  }
  const _sfc_main = {
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
          message: "",
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
          const response = await this.$api.get("ftp-backup/backups");
          if (response.status === "success" && response.data) {
            this.backups = response.data.map((backup) => {
              return {
                id: backup.filename,
                text: backup.filename,
                info: new Date(backup.modified * 1e3).toLocaleString() + " - " + this.formatSize(backup.size),
                url: backup.downloadUrl,
                icon: "archive"
              };
            });
          } else {
            this.backups = [];
          }
          if (response.status === "success" && response.stats) {
            this.stats = response.stats;
          }
        } catch (error) {
          window.panel.notification.error("Failed to load backups");
          this.backups = [];
        } finally {
          this.isLoadingBackups = false;
        }
      },
      async createBackup() {
        this.isCreatingBackup = true;
        try {
          const response = await this.$api.post("ftp-backup/create");
          if (response.status === "success") {
            window.panel.notification.success(response.message);
            this.loadBackups();
            if (response.data && response.data.ftpResult && !response.data.ftpResult.uploaded) {
              this.showFtpWarning(response.data.ftpResult.message || "FTP upload failed", true);
            } else {
              this.dismissWarning();
            }
          } else {
            window.panel.notification.error(response.message);
            if (response.message && response.message.toLowerCase().includes("ftp")) {
              this.showFtpWarning(response.message, true);
            }
          }
        } catch (error) {
          window.panel.notification.error("Failed to create backup");
          this.showFtpWarning("Error: Failed to create backup. FTP connection might have failed.", true);
        } finally {
          this.isCreatingBackup = false;
        }
      },
      showSettingsInfo() {
        this.$refs.settingsDialog.open();
      },
      downloadBackup(item) {
        window.open(item.url, "_blank");
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
          const response = await this.$api.get("ftp-backup/ftp-stats");
          if (response.status === "success" && response.data) {
            this.ftpStats = response.data;
          } else {
            this.ftpStatsError = response.message || "Failed to load FTP server stats";
            this.ftpStats = null;
          }
        } catch (error) {
          this.ftpStatsError = "Failed to connect to FTP server";
          this.ftpStats = null;
        } finally {
          this.isLoadingFtpStats = false;
        }
      },
      // FTP Warning Panel Methods
      showFtpWarning(message, isPersistent = false) {
        this.ftpWarning = {
          show: true,
          message,
          isPersistent
        };
        if (isPersistent) {
          sessionStorage.setItem("ftpBackupWarning", JSON.stringify(this.ftpWarning));
        }
      },
      dismissWarning() {
        this.ftpWarning.show = false;
        sessionStorage.removeItem("ftpBackupWarning");
      },
      checkFtpSettings() {
        const savedWarning = sessionStorage.getItem("ftpBackupWarning");
        if (savedWarning) {
          try {
            this.ftpWarning = JSON.parse(savedWarning);
          } catch (e) {
            sessionStorage.removeItem("ftpBackupWarning");
          }
        }
        this.$api.get("ftp-backup/settings-status").then((response) => {
          if (response.status === "success" && !response.data.configured) {
            this.showFtpWarning("FTP settings are not configured. Backups will be created locally only.", true);
          } else {
            this.dismissWarning();
          }
        }).catch(() => {
        });
      },
      formatSize(bytes) {
        const units = ["B", "KB", "MB", "GB", "TB"];
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
  var _sfc_render = function render() {
    var _vm = this, _c = _vm._self._c;
    return _c("k-panel-inside", { staticClass: "k-ftp-backup-view" }, [_vm.ftpWarning.show ? _c("div", { staticClass: "k-ftp-backup-view-warning" }, [_c("k-box", { staticClass: "k-ftp-backup-view-warning-box", attrs: { "theme": "negative" } }, [_c("k-icon", { attrs: { "type": "alert" } }), _c("span", [_vm._v(_vm._s(_vm.ftpWarning.message))]), _c("k-button", { attrs: { "icon": "settings" }, on: { "click": _vm.showSettingsInfo } }), _c("k-button", { attrs: { "icon": "cancel" }, on: { "click": _vm.dismissWarning } })], 1)], 1) : _vm._e(), _c("div", { staticClass: "k-ftp-backup-view-stats" }, [_c("div", { staticClass: "k-ftp-backup-view-stats-card" }, [_c("h3", [_vm._v("Total Backups")]), _c("p", [_vm._v(_vm._s(_vm.stats.count))])]), _c("div", { staticClass: "k-ftp-backup-view-stats-card" }, [_c("h3", [_vm._v("Total Size")]), _c("p", [_vm._v(_vm._s(_vm.stats.formattedTotalSize))])]), _c("div", { staticClass: "k-ftp-backup-view-stats-card" }, [_c("h3", [_vm._v("Latest Backup")]), _vm.stats.latestBackup ? _c("p", [_vm._v(_vm._s(_vm.stats.latestBackup.formattedDate))]) : _c("p", [_vm._v("None")]), _vm.stats.latestBackup ? _c("div", { staticClass: "k-ftp-backup-view-stats-card-latest" }, [_vm._v(" " + _vm._s(_vm.stats.latestBackup.filename) + " ")]) : _vm._e()])]), _c("div", { staticClass: "k-ftp-backup-view-section" }, [_c("div", { staticClass: "k-ftp-backup-view-actions" }, [_c("k-button-group", [_c("k-button", { attrs: { "icon": "upload", "disabled": _vm.isLoading || _vm.isCreatingBackup, "progress": _vm.isCreatingBackup }, on: { "click": _vm.createBackup } }, [_vm._v(" Create Backup Now ")]), _c("k-button", { attrs: { "icon": "refresh", "disabled": _vm.isLoading || _vm.isCreatingBackup, "progress": _vm.isLoadingBackups }, on: { "click": _vm.loadBackups } }), _c("k-button", { attrs: { "icon": "info" }, on: { "click": _vm.showSettingsInfo } }), _c("k-button", { attrs: { "icon": "server", "disabled": _vm.isLoading || _vm.isCreatingBackup || _vm.isLoadingFtpStats, "progress": _vm.isLoadingFtpStats, "title": "Show FTP Server Stats" }, on: { "click": _vm.showFtpServerStats } })], 1)], 1)]), _c("div", { staticClass: "k-tab-content" }, [_vm.isLoadingBackups ? _c("div", { staticClass: "k-ftp-backup-view-loading" }, [_c("k-loader")], 1) : _vm.backups.length === 0 ? _c("div", { staticClass: "k-ftp-backup-view-backup-list" }, [_c("div", { staticClass: "k-ftp-backup-view-backup-list-empty" }, [_vm._v(" No backups available ")])]) : _c("k-collection", { attrs: { "items": _vm.backups, "layout": "list" }, scopedSlots: _vm._u([{ key: "options", fn: function({ item }) {
      return [_c("k-button", { attrs: { "icon": "download" }, on: { "click": function($event) {
        return _vm.downloadBackup(item);
      } } })];
    } }]) })], 1), _c("k-dialog", { ref: "settingsDialog", attrs: { "size": "large" } }, [_c("div", { staticClass: "k-ftp-backup-dialog-content" }, [_c("h2", { staticClass: "k-ftp-backup-dialog-title" }, [_vm._v("FTP Backup Settings")]), _c("div", { staticClass: "k-ftp-backup-dialog-section" }, [_c("h3", [_vm._v("Configuration")]), _c("p", [_vm._v("FTP settings are managed through your site config file.")]), _c("p", [_vm._v("Add the following to your "), _c("code", [_vm._v("site/config/config.php")]), _vm._v(" file:")]), _c("div", { staticClass: "k-ftp-backup-dialog-code" }, [_c("pre", [_vm._v("'tearoom1.kirby-ftp-backup' => [\n    'ftpProtocol' => 'ftp', // ftp, ftps or sftp\n    'ftpHost' => 'your-ftp-host.com',\n    'ftpPort' => 21,\n    'ftpUsername' => 'your-username',\n    'ftpPassword' => 'your-password',\n    'ftpDirectory' => 'backups',\n    'ftpPassive' => true,\n    'ftpPrivateKey' => '', // for sftp\n    'ftpPassphrase' => '' // for sftp\n]")])]), _vm._v(" Find more about those options in the Readme.md file ")]), _c("div", { staticClass: "k-ftp-backup-dialog-section" }, [_c("h3", [_vm._v("Automatic Backups")]), _c("p", [_vm._v("For automatic backups, set up a cron job to run:")]), _c("div", { staticClass: "k-ftp-backup-dialog-code" }, [_c("pre", [_vm._v("php /path/to/site/plugins/kirby-ftp-backup/run.php")])]), _c("p", { staticClass: "k-ftp-backup-dialog-hint" }, [_vm._v("Example crontab entry (daily at 2AM):")]), _c("div", { staticClass: "k-ftp-backup-dialog-code" }, [_c("pre", [_vm._v("0 2 * * * php /path/to/site/plugins/kirby-ftp-backup/run.php")])])])]), _c("k-button-group", { attrs: { "slot": "footer" }, slot: "footer" }, [_c("k-button", { attrs: { "icon": "check" }, on: { "click": function($event) {
      return _vm.$refs.settingsDialog.close();
    } } }, [_vm._v(" Close ")])], 1)], 1), _c("k-dialog", { ref: "ftpStatsDialog", attrs: { "button": "close", "size": "large", "theme": "info" } }, [_c("k-headline", [_vm._v("FTP Server Stats")]), _vm.isLoadingFtpStats ? _c("div", { staticClass: "k-ftp-backup-dialog-loading" }, [_c("k-icon", { staticClass: "k-ftp-backup-spinner", attrs: { "type": "loader" } }), _c("span", [_vm._v("Loading FTP server stats...")])], 1) : _vm.ftpStatsError ? _c("div", { staticClass: "k-ftp-backup-dialog-error" }, [_c("k-box", { attrs: { "theme": "negative" } }, [_vm._v(" " + _vm._s(_vm.ftpStatsError) + " ")])], 1) : _vm.ftpStats ? _c("div", { staticClass: "k-ftp-backup-view-server-stats" }, [_c("div", { staticClass: "k-ftp-backup-view-stats" }, [_c("div", { staticClass: "k-ftp-backup-view-stats-card" }, [_c("h3", [_vm._v("Files on Server")]), _c("p", [_vm._v(_vm._s(_vm.ftpStats.count))])]), _c("div", { staticClass: "k-ftp-backup-view-stats-card" }, [_c("h3", [_vm._v("Total Size")]), _c("p", [_vm._v(_vm._s(_vm.ftpStats.formattedTotalSize))])]), _c("div", { staticClass: "k-ftp-backup-view-stats-card" }, [_c("h3", [_vm._v("Latest Backup")]), _c("p", [_vm._v(_vm._s(_vm.ftpStats.latestModified))])])]), _c("div", { staticClass: "k-ftp-backup-dialog-section" }, [_c("h3", [_vm._v("Files on FTP Server")]), _vm.ftpStats.files && _vm.ftpStats.files.length > 0 ? _c("div", [_c("table", { staticClass: "k-ftp-backup-files-table" }, [_c("thead", [_c("tr", [_c("th", [_vm._v("Filename")]), _c("th", [_vm._v("Size")]), _c("th", [_vm._v("Date")])])]), _c("tbody", _vm._l(_vm.ftpStats.files, function(file) {
      return _c("tr", { key: file.filename }, [_c("td", [_vm._v(_vm._s(file.filename))]), _c("td", [_vm._v(_vm._s(file.formattedSize))]), _c("td", [_vm._v(_vm._s(file.formattedDate))])]);
    }), 0)])]) : _c("div", { staticClass: "k-ftp-backup-view-backup-list-empty" }, [_vm._v(" No backups available on FTP server ")])])]) : _vm._e()], 1)], 1);
  };
  var _sfc_staticRenderFns = [];
  _sfc_render._withStripped = true;
  var __component__ = /* @__PURE__ */ normalizeComponent(
    _sfc_main,
    _sfc_render,
    _sfc_staticRenderFns
  );
  __component__.options.__file = "/Users/mathis/Work/Basic/kirby-basic/site/plugins/kirby-ftp-backup/js/components/FtpBackupView.vue";
  const FtpBackupView = __component__.exports;
  panel.plugin("tearoom1/kirby-ftp-backup", {
    components: {
      "ftp-backup-view": FtpBackupView
    }
  });
})();
