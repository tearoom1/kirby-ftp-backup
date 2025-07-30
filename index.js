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
          } else {
            window.panel.notification.error(response.message);
          }
        } catch (error) {
          window.panel.notification.error("Failed to create backup");
        } finally {
          this.isCreatingBackup = false;
        }
      },
      showSettingsInfo() {
        this.$refs.settingsDialog.open();
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
      },
      downloadBackup(item) {
        window.open(item.url, "_blank");
      }
    }
  };
  var _sfc_render = function render() {
    var _vm = this, _c = _vm._self._c;
    return _c("k-panel-inside", { staticClass: "k-ftp-backup-view" }, [_c("div", { staticClass: "k-ftp-backup-view-stats" }, [_c("div", { staticClass: "k-ftp-backup-view-stats-card" }, [_c("h3", [_vm._v("Total Backups")]), _c("p", [_vm._v(_vm._s(_vm.stats.count))])]), _c("div", { staticClass: "k-ftp-backup-view-stats-card" }, [_c("h3", [_vm._v("Total Size")]), _c("p", [_vm._v(_vm._s(_vm.stats.formattedTotalSize))])]), _c("div", { staticClass: "k-ftp-backup-view-stats-card" }, [_c("h3", [_vm._v("Latest Backup")]), _vm.stats.latestBackup ? _c("p", [_vm._v(_vm._s(_vm.stats.latestBackup.formattedDate))]) : _c("p", [_vm._v("None")]), _vm.stats.latestBackup ? _c("div", { staticClass: "k-ftp-backup-view-stats-card-latest" }, [_vm._v(" " + _vm._s(_vm.stats.latestBackup.filename) + " ")]) : _vm._e()])]), _c("div", { staticClass: "k-ftp-backup-view-section" }, [_c("div", { staticClass: "k-ftp-backup-view-actions" }, [_c("k-button-group", [_c("k-button", { attrs: { "icon": "upload", "disabled": _vm.isLoading, "progress": _vm.isCreatingBackup }, on: { "click": _vm.createBackup } }, [_vm._v(" Create Backup Now ")]), _c("k-button", { attrs: { "icon": "refresh", "disabled": _vm.isLoading, "progress": _vm.isLoadingBackups }, on: { "click": _vm.loadBackups } }), _c("k-button", { attrs: { "icon": "info" }, on: { "click": _vm.showSettingsInfo } })], 1)], 1)]), _c("div", { staticClass: "k-tab-content" }, [_vm.isLoadingBackups ? _c("div", { staticClass: "k-ftp-backup-view-loading" }, [_c("k-loader")], 1) : _vm.backups.length === 0 ? _c("div", { staticClass: "k-ftp-backup-view-backup-list" }, [_c("div", { staticClass: "k-ftp-backup-view-backup-list-empty" }, [_vm._v(" No backups available ")])]) : _c("k-collection", { attrs: { "items": _vm.backups, "layout": "list" }, scopedSlots: _vm._u([{ key: "options", fn: function({ item }) {
      return [_c("k-button", { attrs: { "icon": "download" }, on: { "click": function($event) {
        return _vm.downloadBackup(item);
      } } })];
    } }]) })], 1), _c("k-dialog", { ref: "settingsDialog", attrs: { "size": "large" } }, [_c("div", { staticClass: "k-ftp-backup-dialog-content" }, [_c("h2", { staticClass: "k-ftp-backup-dialog-title" }, [_vm._v("FTP Backup Settings")]), _c("div", { staticClass: "k-ftp-backup-dialog-section" }, [_c("h3", [_vm._v("Configuration")]), _c("p", [_vm._v("FTP settings are managed through your site config file.")]), _c("p", [_vm._v("Add the following to your "), _c("code", [_vm._v("site/config/config.php")]), _vm._v(" file:")]), _c("div", { staticClass: "k-ftp-backup-dialog-code" }, [_c("pre", [_vm._v("'tearoom1.ftp-backup' => [\n    'ftpHost' => 'your-ftp-host.com',\n    'ftpPort' => 21,\n    'ftpUsername' => 'your-username',\n    'ftpPassword' => 'your-password',\n    'ftpDirectory' => 'backups',\n    'ftpSsl' => false,\n    'ftpPassive' => true,\n    'backupDirectory' => 'content/.backups',\n    'backupRetention' => 10,\n    'deleteFromFtp' => true\n]")])])]), _c("div", { staticClass: "k-ftp-backup-dialog-section" }, [_c("h3", [_vm._v("Automatic Backups")]), _c("p", [_vm._v("For automatic backups, set up a cron job to run:")]), _c("div", { staticClass: "k-ftp-backup-dialog-code" }, [_c("pre", [_vm._v("php /path/to/site/plugins/kirby-ftp-backup/run.php")])]), _c("p", { staticClass: "k-ftp-backup-dialog-hint" }, [_vm._v("Example crontab entry (daily at 2AM):")]), _c("div", { staticClass: "k-ftp-backup-dialog-code" }, [_c("pre", [_vm._v("0 2 * * * php /path/to/site/plugins/kirby-ftp-backup/run.php")])])])]), _c("k-button-group", { attrs: { "slot": "footer" }, slot: "footer" }, [_c("k-button", { attrs: { "icon": "check" }, on: { "click": function($event) {
      return _vm.$refs.settingsDialog.close();
    } } }, [_vm._v(" Close ")])], 1)], 1)], 1);
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
  panel.plugin("tearoom1/ftp-backup", {
    components: {
      "ftp-backup-view": FtpBackupView
    }
  });
})();
