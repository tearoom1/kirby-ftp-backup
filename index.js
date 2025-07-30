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
                link: backup.downloadUrl,
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
      handleBackupAction(action, item) {
        if (action === "download") {
          window.open("/" + item.link, "_blank");
        }
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
    return _c("k-panel-inside", { staticClass: "k-ftp-backup-view" }, [_c("div", { staticClass: "k-ftp-backup-view-stats" }, [_c("div", { staticClass: "k-ftp-backup-view-stats-card" }, [_c("h3", [_vm._v("Total Backups")]), _c("p", [_vm._v(_vm._s(_vm.stats.count))])]), _c("div", { staticClass: "k-ftp-backup-view-stats-card" }, [_c("h3", [_vm._v("Total Size")]), _c("p", [_vm._v(_vm._s(_vm.stats.formattedTotalSize))])]), _c("div", { staticClass: "k-ftp-backup-view-stats-card" }, [_c("h3", [_vm._v("Latest Backup")]), _vm.stats.latestBackup ? _c("p", [_vm._v(_vm._s(_vm.stats.latestBackup.formattedDate))]) : _c("p", [_vm._v("None")]), _vm.stats.latestBackup ? _c("div", { staticClass: "k-ftp-backup-view-stats-card-latest" }, [_vm._v(" " + _vm._s(_vm.stats.latestBackup.filename) + " ")]) : _vm._e()])]), _c("div", { staticClass: "k-ftp-backup-view-section" }, [_c("div", { staticClass: "k-ftp-backup-view-actions" }, [_c("k-button-group", [_c("k-button", { attrs: { "icon": "upload", "disabled": _vm.isLoading, "progress": _vm.isCreatingBackup }, on: { "click": _vm.createBackup } }, [_vm._v(" Create Backup Now ")]), _c("k-button", { attrs: { "icon": "refresh", "disabled": _vm.isLoading, "progress": _vm.isLoadingBackups }, on: { "click": _vm.loadBackups } })], 1)], 1)]), _c("div", { staticClass: "k-tab-content" }, [_vm.isLoadingBackups ? _c("div", { staticClass: "k-ftp-backup-view-loading" }, [_c("k-loader")], 1) : _vm.backups.length === 0 ? _c("div", { staticClass: "k-ftp-backup-view-backup-list" }, [_c("div", { staticClass: "k-ftp-backup-view-backup-list-empty" }, [_vm._v(" No backups available ")])]) : _c("k-collection", { attrs: { "items": _vm.backups, "layout": "list" }, on: { "action": _vm.handleBackupAction } })], 1), _c("div", { staticClass: "k-ftp-backup-view-settings-info" }, [_c("k-box", { attrs: { "theme": "info" } }, [_c("h3", [_vm._v("FTP Settings")]), _c("p", [_vm._v("FTP settings are managed through your site config file.")]), _c("p", [_vm._v("Add the following to your "), _c("code", [_vm._v("site/config/config.php")]), _vm._v(" file:")]), _c("pre", [_vm._v("'tearoom1.ftp-backup' => [\n    'ftpHost' => 'your-ftp-host.com',\n    'ftpPort' => 21,\n    'ftpUsername' => 'your-username',\n    'ftpPassword' => 'your-password',\n    'ftpDirectory' => '/backups',\n    'ftpSsl' => false,\n    'ftpPassive' => true,\n    'backupDirectory' => 'content/.backups',\n    'backupRetention' => 10,\n    'deleteFromFtp' => true\n]\n        ")]), _c("p", [_vm._v("For automatic backups, set up a cron job to run:")]), _c("pre", [_vm._v("php /path/to/site/plugins/kirby-ftp-backup/run.php")])])], 1)]);
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
