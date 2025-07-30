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
      stats: Object,
      cronCommand: String
    },
    data() {
      return {
        activeTab: "settings",
        isLoading: false,
        isCreatingBackup: false,
        isLoadingBackups: false,
        ftpSettings: {
          host: "",
          port: 21,
          username: "",
          password: "",
          directory: "/",
          passive: true,
          ssl: false
        },
        backups: []
      };
    },
    computed: {
      formFields() {
        return {
          host: {
            label: "FTP Host",
            type: "text",
            required: true
          },
          port: {
            label: "FTP Port",
            type: "number",
            default: 21
          },
          username: {
            label: "Username",
            type: "text",
            required: true
          },
          password: {
            label: "Password",
            type: "password",
            help: "Leave empty to keep existing password"
          },
          directory: {
            label: "Remote Directory",
            type: "text",
            default: "/"
          },
          passive: {
            label: "Passive Mode",
            type: "toggle",
            default: true,
            text: ["Off", "On"]
          },
          ssl: {
            label: "Use SSL/TLS",
            type: "toggle",
            default: false,
            text: ["Off", "On"]
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
          const response = await this.$api.get("ftp-backup/settings");
          this.ftpSettings = response;
        } catch (error) {
          this.$store.dispatch("notification/error", "Failed to load FTP settings");
        } finally {
          this.isLoading = false;
        }
      },
      async saveSettings() {
        this.isLoading = true;
        try {
          const response = await this.$api.post("ftp-backup/settings", this.ftpSettings);
          if (response.status === "success") {
            this.$store.dispatch("notification/success", response.message);
          } else {
            this.$store.dispatch("notification/error", response.message);
          }
        } catch (error) {
          this.$store.dispatch("notification/error", "Failed to save FTP settings");
        } finally {
          this.isLoading = false;
        }
      },
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
          this.$store.dispatch("notification/error", "Failed to load backups");
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
            this.$store.dispatch("notification/success", response.message);
            this.loadBackups();
          } else {
            this.$store.dispatch("notification/error", response.message);
          }
        } catch (error) {
          this.$store.dispatch("notification/error", "Failed to create backup");
        } finally {
          this.isCreatingBackup = false;
        }
      },
      handleBackupAction(action, item) {
        if (action === "download") {
          window.open(item.link, "_blank");
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
      },
      copyCronCommand() {
        navigator.clipboard.writeText(this.cronCommand);
        this.$store.dispatch("notification/success", "Command copied to clipboard");
      },
      copyCrontabEntry() {
        navigator.clipboard.writeText(`0 2 * * * ${this.cronCommand}`);
        this.$store.dispatch("notification/success", "Crontab entry copied to clipboard");
      }
    }
  };
  var _sfc_render = function render() {
    var _vm = this, _c = _vm._self._c;
    return _c("k-panel-inside", { staticClass: "k-ftp-backup-view" }, [_c("div", { staticClass: "k-ftp-backup-view-stats" }, [_c("div", { staticClass: "k-ftp-backup-view-stats-card" }, [_c("h3", [_vm._v("Total Backups")]), _c("p", [_vm._v(_vm._s(_vm.stats.count))])]), _c("div", { staticClass: "k-ftp-backup-view-stats-card" }, [_c("h3", [_vm._v("Total Size")]), _c("p", [_vm._v(_vm._s(_vm.stats.formattedTotalSize))])]), _c("div", { staticClass: "k-ftp-backup-view-stats-card" }, [_c("h3", [_vm._v("Latest Backup")]), _vm.stats.latestBackup ? _c("p", [_vm._v(_vm._s(_vm.stats.latestBackup.formattedDate))]) : _c("p", [_vm._v("None")]), _vm.stats.latestBackup ? _c("div", { staticClass: "k-ftp-backup-view-stats-card-latest" }, [_vm._v(" " + _vm._s(_vm.stats.latestBackup.filename) + " ")]) : _vm._e()])]), _c("div", { staticClass: "k-ftp-backup-view-section" }, [_c("div", { staticClass: "k-ftp-backup-view-actions" }, [_c("k-button-group", [_c("k-button", { attrs: { "icon": "upload", "disabled": _vm.isLoading, "progress": _vm.isCreatingBackup }, on: { "click": _vm.createBackup } }, [_vm._v(" Create Backup Now ")]), _c("k-button", { attrs: { "icon": "refresh", "disabled": _vm.isLoading, "progress": _vm.isLoadingBackups }, on: { "click": _vm.loadBackups } })], 1)], 1)]), _c("k-tabs", { attrs: { "tabs": [
      { name: "settings", label: "Settings" },
      { name: "backups", label: "Backups" },
      { name: "cron", label: "Cron Job" }
    ] }, model: { value: _vm.activeTab, callback: function($$v) {
      _vm.activeTab = $$v;
    }, expression: "activeTab" } }), _vm.activeTab === "settings" ? _c("k-tab", { attrs: { "name": "settings" } }, [_c("k-form", { attrs: { "fields": _vm.formFields }, on: { "submit": _vm.saveSettings }, model: { value: _vm.ftpSettings, callback: function($$v) {
      _vm.ftpSettings = $$v;
    }, expression: "ftpSettings" } })], 1) : _vm._e(), _vm.activeTab === "backups" ? _c("k-tab", { attrs: { "name": "backups" } }, [_vm.isLoadingBackups ? _c("div", { staticClass: "k-ftp-backup-view-loading" }, [_c("k-loader")], 1) : _vm.backups.length === 0 ? _c("div", { staticClass: "k-ftp-backup-view-backup-list" }, [_c("div", { staticClass: "k-ftp-backup-view-backup-list-empty" }, [_vm._v(" No backups available ")])]) : _c("k-collection", { attrs: { "items": _vm.backups, "layout": "list" }, on: { "action": _vm.handleBackupAction } })], 1) : _vm._e(), _vm.activeTab === "cron" ? _c("k-tab", { attrs: { "name": "cron" } }, [_c("div", { staticClass: "k-ftp-backup-view-section" }, [_c("h2", [_vm._v("Cron Job Setup")]), _c("p", [_vm._v("Use the following command in your crontab to schedule automatic backups:")]), _c("div", { staticClass: "k-ftp-backup-view-cron" }, [_vm._v(" " + _vm._s(_vm.cronCommand) + " "), _c("button", { on: { "click": _vm.copyCronCommand } }, [_c("k-icon", { attrs: { "type": "copy" } })], 1)]), _c("p", [_vm._v("Example crontab entry to run daily at 2 AM:")]), _c("div", { staticClass: "k-ftp-backup-view-cron" }, [_vm._v(" 0 2 * * * " + _vm._s(_vm.cronCommand) + " "), _c("button", { on: { "click": _vm.copyCrontabEntry } }, [_c("k-icon", { attrs: { "type": "copy" } })], 1)])])]) : _vm._e()], 1);
  };
  var _sfc_staticRenderFns = [];
  _sfc_render._withStripped = true;
  var __component__ = /* @__PURE__ */ normalizeComponent(
    _sfc_main,
    _sfc_render,
    _sfc_staticRenderFns
  );
  __component__.options.__file = "/Users/mathis/Work/Basic/my-kirby-starter/site/plugins/kirby-ftp-backup/js/components/FtpBackupView.vue";
  const FtpBackupView = __component__.exports;
  panel.plugin("tearoom1/ftp-backup", {
    components: {
      "ftp-backup-view": FtpBackupView
    }
  });
})();
